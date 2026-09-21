<?php

use App\Actions\SyncSalesOrders;
use App\Exceptions\SalesOrderSyncInterrupted;
use App\Jobs\RefreshSalesOrders;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));
});

it('queues a requested customer once without contacting NetSuite in the command', function () {
    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--queue' => true])->assertSuccessful();
    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--queue' => true])->assertSuccessful();

    $this->assertDatabaseCount('jobs', 1);
    expect(DB::table('jobs')->sole()->queue)->toBe('sales-orders');
    Http::assertNothingSent();
});

it('dispatches only due active registered customers', function () {
    Queue::fake();
    Company::factory()->create(['netsuite_id' => 16, 'is_active' => true]);
    Company::factory()->create(['netsuite_id' => 17, 'is_active' => true, 'sales_orders_next_sync_at' => now()]);
    Company::factory()->create(['netsuite_id' => 18, 'is_active' => false]);
    Company::factory()->create(['netsuite_id' => 19, 'is_active' => true, 'sales_orders_next_sync_at' => now()->addMinute()]);

    $this->artisan('milkstool:dispatch-sales-order-refreshes')->assertSuccessful();

    Queue::assertPushed(RefreshSalesOrders::class, 2);
    Queue::assertPushed(RefreshSalesOrders::class, fn ($job) => $job->customerId === 16);
    Queue::assertPushed(RefreshSalesOrders::class, fn ($job) => $job->customerId === 17);
    Http::assertNothingSent();
});

it('lists due customers without enqueueing in dry run mode', function () {
    Queue::fake();
    Company::factory()->create(['netsuite_id' => 16, 'is_active' => true]);

    $this->artisan('milkstool:dispatch-sales-order-refreshes', ['--dry-run' => true])
        ->expectsOutput('Due: NetSuite customer 16')->expectsOutput('1 customers due. No jobs queued.')->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('runs a serialized queued refresh and makes the customer no longer due', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([sourceOrder()]))->push(sourcePage([sourceLine()]))->push(sourcePage([sourceOrder()]))]);
    RefreshSalesOrders::dispatch(16);

    $queued = Queue::connection('netsuite')->pop('sales-orders');
    $queued->fire();

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('transactions', 1);
    expect(Company::query()->sole()->sales_orders_next_sync_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 18:00:00');
    $this->artisan('milkstool:dispatch-sales-order-refreshes', ['--dry-run' => true])
        ->expectsOutput('0 customers due. No jobs queued.')->assertSuccessful();
    Http::assertSentCount(5);
});

it('allows the queue to retry transient NetSuite failures', function (int $status) {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], $status)]);
    $job = (new RefreshSalesOrders(16))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(SyncSalesOrders::class)))->toThrow(RequestException::class);

    $job->assertNotFailed();
})->with([408, 429, 503]);

it('allows the queue to retry a connection failure or a busy customer lock', function (bool $busy) {
    if ($busy) {
        Cache::lock('netsuite-sales-orders:16', 600)->get();
    } else {
        Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::failedConnection()]);
    }
    $job = (new RefreshSalesOrders(16))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(SyncSalesOrders::class)))->toThrow($busy ? SalesOrderSyncInterrupted::class : ConnectionException::class);

    $job->assertNotFailed();
})->with([true, false]);

it('fails permanent NetSuite errors immediately and delays the next baseline attempt', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'sales_orders_synced_at' => '2026-09-15 12:00:00']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], 403)]);
    RefreshSalesOrders::dispatch(16);

    Queue::connection('netsuite')->pop('sales-orders')->fire();

    $this->assertDatabaseCount('jobs', 0);
    expect($company->refresh()->sales_orders_sync_error)->toContain('Background refresh failed');
    expect($company->sales_orders_next_sync_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 18:00:00');
    expect($company->sales_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-15 12:00:00');
    Http::assertSentCount(1);
});

it('fails invalid source data without retrying the job', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([]))]);
    $job = (new RefreshSalesOrders(16))->withFakeQueueInteractions();

    $job->handle(app(SyncSalesOrders::class));

    $job->assertFailedWith(RuntimeException::class);
    Http::assertSentCount(1);
});

it('keeps automatic dispatch disabled until explicitly enabled', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'milkstool:dispatch-sales-order-refreshes'));
    config()->set('netsuite-sync.scheduled', false);
    expect($event->filtersPass(app()))->toBeFalse();

    config()->set('netsuite-sync.scheduled', true);
    expect($event->filtersPass(app()))->toBeTrue();
    expect($event->isDue(app()))->toBeTrue();
    $this->travelTo(now()->addMinute());
    expect($event->isDue(app()))->toBeFalse();
});

it('reserves the queue job longer than its execution timeout', function () {
    RefreshSalesOrders::dispatch(16);
    $job = Queue::connection('netsuite')->pop('sales-orders');

    expect($job->timeout())->toBeLessThan(config('queue.connections.netsuite.retry_after'));
    expect($job->maxTries())->toBe(3);
    expect($job->backoff())->toBe('60,300');
});

it('does not overwrite a newer successful sync when an older queued job fails', function () {
    $job = new RefreshSalesOrders(16);
    $company = Company::factory()->create(['netsuite_id' => 16, 'sales_orders_synced_at' => now()->addMinute(),
        'sales_orders_next_sync_at' => '2026-09-16 18:01:00']);

    $job->failed(new RuntimeException('An older job failed'));

    expect($company->refresh()->sales_orders_sync_error)->toBeNull();
    expect($company->sales_orders_next_sync_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 18:01:00');
});
