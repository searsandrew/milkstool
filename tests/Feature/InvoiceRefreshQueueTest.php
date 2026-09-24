<?php

use App\Actions\SyncInvoices;
use App\Exceptions\ReceivableSyncInterrupted;
use App\Jobs\RefreshInvoices;
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
    Company::factory()->create(['id' => 16, 'is_active' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));
});

it('queues a requested customer once without contacting NetSuite in the command', function () {
    $this->artisan('milkstool:sync-invoices', ['customer' => '16', '--queue' => true])->assertSuccessful();
    $this->artisan('milkstool:sync-invoices', ['customer' => '16', '--queue' => true])->assertSuccessful();

    $this->assertDatabaseCount('jobs', 1);
    expect(DB::table('jobs')->sole()->queue)->toBe('invoices');
    Http::assertNothingSent();
});

it('dispatches only due active registered customers', function () {
    Queue::fake();
    Company::factory()->create(['id' => 17, 'is_active' => true, 'invoices_next_sync_at' => now()]);
    Company::factory()->create(['id' => 18, 'is_active' => false]);
    Company::factory()->create(['id' => 19, 'is_active' => true, 'invoices_next_sync_at' => now()->addMinute()]);

    $this->artisan('milkstool:dispatch-invoice-refreshes')->assertSuccessful();

    Queue::assertPushed(RefreshInvoices::class, 2);
    Queue::assertPushed(RefreshInvoices::class, fn ($job) => $job->customerId === 16);
    Queue::assertPushed(RefreshInvoices::class, fn ($job) => $job->customerId === 17);
    Http::assertNothingSent();
});

it('lists due customers without enqueueing in dry run mode', function () {
    Queue::fake();

    $this->artisan('milkstool:dispatch-invoice-refreshes', ['--dry-run' => true])
        ->expectsOutput('Due: NetSuite customer 16')->expectsOutput('1 customers due. No jobs queued.')->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('runs a serialized queued refresh and makes the customer no longer due', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([]))]);
    RefreshInvoices::dispatch(16);

    $queued = Queue::connection('netsuite')->pop('invoices');
    $queued->fire();

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('transactions', 0);
    expect(Company::query()->sole()->invoices_backfilled_at)->not->toBeNull();
    expect(Company::query()->sole()->invoices_next_sync_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 18:00:00');
    $this->artisan('milkstool:dispatch-invoice-refreshes', ['--dry-run' => true])
        ->expectsOutput('0 customers due. No jobs queued.')->assertSuccessful();
    Http::assertSentCount(4);
});

it('allows the queue to retry transient NetSuite failures', function (int $status) {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], $status)]);
    $job = (new RefreshInvoices(16))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(SyncInvoices::class)))->toThrow(RequestException::class);

    $job->assertNotFailed();
})->with([408, 429, 503]);

it('allows the queue to retry a connection failure or a busy customer lock', function (bool $busy) {
    if ($busy) {
        Cache::lock('netsuite-invoices:16', 600)->get();
    } else {
        Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::failedConnection()]);
    }
    $job = (new RefreshInvoices(16))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(SyncInvoices::class)))->toThrow($busy ? ReceivableSyncInterrupted::class : ConnectionException::class);

    $job->assertNotFailed();
})->with([true, false]);

it('fails permanent NetSuite errors immediately and delays the next baseline attempt', function () {
    $company = Company::query()->where('id', 16)->firstOrFail();
    $company->forceFill(['invoices_synced_at' => '2026-09-15 12:00:00'])->save();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], 403)]);
    RefreshInvoices::dispatch(16);

    Queue::connection('netsuite')->pop('invoices')->fire();

    $this->assertDatabaseCount('jobs', 0);
    expect($company->refresh()->invoices_sync_error)->toContain('Background refresh failed');
    expect($company->invoices_next_sync_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 18:00:00');
    expect($company->invoices_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-15 12:00:00');
    Http::assertSentCount(1);
});

it('fails invalid source data without retrying the job', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([]))]);
    $job = (new RefreshInvoices(16))->withFakeQueueInteractions();

    $job->handle(app(SyncInvoices::class));

    $job->assertFailedWith(RuntimeException::class);
    Http::assertSentCount(1);
});

it('keeps automatic dispatch disabled until explicitly enabled', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'milkstool:dispatch-invoice-refreshes'));
    config()->set('netsuite-sync.scheduled', false);
    expect($event->filtersPass(app()))->toBeFalse();

    config()->set('netsuite-sync.scheduled', true);
    expect($event->filtersPass(app()))->toBeTrue();
    expect($event->isDue(app()))->toBeTrue();
    $this->travelTo(now()->addMinute());
    expect($event->isDue(app()))->toBeFalse();
});

it('reserves the queue job longer than its execution timeout', function () {
    RefreshInvoices::dispatch(16);
    $job = Queue::connection('netsuite')->pop('invoices');

    expect($job->timeout())->toBeLessThan(config('queue.connections.netsuite.retry_after'));
    expect($job->maxTries())->toBe(3);
    expect($job->backoff())->toBe('60,300');
});

it('does not overwrite a newer successful sync when an older queued job fails', function () {
    $job = new RefreshInvoices(16);
    $company = Company::query()->where('id', 16)->firstOrFail();
    $company->forceFill(['invoices_synced_at' => now()->addMinute(),
        'invoices_next_sync_at' => '2026-09-16 18:01:00'])->save();

    $job->failed(new RuntimeException('An older job failed'));

    expect($company->refresh()->invoices_sync_error)->toBeNull();
    expect($company->invoices_next_sync_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 18:01:00');
});

it('skips a customer deactivated after dispatch', function () {
    Company::query()->where('id', 16)->update(['is_active' => false]);
    (new RefreshInvoices(16))->handle(app(SyncInvoices::class));
    Http::assertNothingSent();
});
