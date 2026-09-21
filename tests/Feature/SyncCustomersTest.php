<?php

use App\Actions\SyncCustomers;
use App\Jobs\RefreshCustomers;
use App\Jobs\RefreshSalesOrders;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('discovers active customers across pages without downloading transactions or changing sync checkpoints', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'sales_orders_checkpoint_at' => '2026-09-01 10:00:00',
        'sales_orders_synced_at' => '2026-09-01 10:02:00', 'sales_orders_next_sync_at' => '2026-09-01 16:02:00']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([array_replace(sourceCustomer(), ['stage' => 'CUSTOMER'])], true))
        ->push(sourcePage([
            array_replace(sourceCustomer(), ['id' => '17', 'stage' => 'CUSTOMER']),
            array_replace(sourceCustomer(), ['id' => '18', 'stage' => 'CUSTOMER', 'isinactive' => 'T']),
        ]))]);

    $this->artisan('milkstool:sync-customers')->assertSuccessful();

    $this->assertDatabaseCount('companies', 2);
    $this->assertDatabaseCount('transactions', 0);
    $this->assertDatabaseCount('jobs', 0);
    expect($company->refresh()->name)->toBe('Example Customer');
    expect($company->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 10:00:00');
    expect($company->sales_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 10:02:00');
    expect($company->sales_orders_next_sync_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 16:02:00');
    expect(Company::query()->where('netsuite_id', 17)->sole()->sales_orders_next_sync_at)->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request['q'], "WHERE stage = 'CUSTOMER' AND id > 16 ORDER BY id"));
});

it('repeats discovery without duplicates or unnecessary identity writes', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        array_replace(sourceCustomer(), ['stage' => 'CUSTOMER']),
    ]))]);
    app(SyncCustomers::class)->handle();

    $counts = app(SyncCustomers::class)->handle();

    expect($counts['unchanged'])->toBe(1);
    expect($counts['created'])->toBe(0);
    expect($counts['updated'])->toBe(0);
    $this->assertDatabaseCount('companies', 1);
    Http::assertSentCount(2);
});

it('deactivates only explicitly inactive customers while retaining their orders and missing customers', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $missing = Company::factory()->create(['netsuite_id' => 17]);
    $order = Transaction::factory()->for($company)->create();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        array_replace(sourceCustomer(), ['stage' => 'CUSTOMER', 'isinactive' => 'T']),
    ]))]);

    app(SyncCustomers::class)->handle();

    expect($company->refresh()->is_active)->toBeFalse();
    expect($missing->refresh()->is_active)->toBeTrue();
    $this->assertModelExists($order);
    Http::assertSentCount(1);
});

it('makes reactivated customers due immediately', function () {
    $this->freezeSecond();
    $company = Company::factory()->create(['netsuite_id' => 16, 'is_active' => false, 'sales_orders_next_sync_at' => now()->addHours(6)]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        array_replace(sourceCustomer(), ['stage' => 'CUSTOMER']),
    ]))]);

    app(SyncCustomers::class)->handle();

    expect($company->refresh()->is_active)->toBeTrue();
    expect($company->sales_orders_next_sync_at->equalTo(now()))->toBeTrue();
    Http::assertSentCount(1);
});

it('previews changes without creating or updating customers', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'name' => 'Existing name']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        array_replace(sourceCustomer(), ['stage' => 'CUSTOMER']),
        array_replace(sourceCustomer(), ['id' => '17', 'stage' => 'CUSTOMER']),
    ]))]);

    $this->artisan('milkstool:sync-customers', ['--dry-run' => true])->assertSuccessful();

    expect($company->refresh()->name)->toBe('Existing name');
    $this->assertDatabaseCount('companies', 1);
    $this->assertDatabaseCount('jobs', 0);
    Http::assertSentCount(1);
});

it('does not save a partial directory when a later page fails', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([array_replace(sourceCustomer(), ['stage' => 'CUSTOMER'])], true))->push([], 503)]);

    $this->artisan('milkstool:sync-customers')->assertFailed();

    $this->assertDatabaseCount('companies', 0);
    Http::assertSentCount(2);
    expect(Cache::lock('netsuite-customer-discovery', 600)->get())->toBeTrue();
});

it('rejects malformed customer pages without changing the directory', function (array $page) {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response($page)]);

    $this->artisan('milkstool:sync-customers')->assertFailed();

    $this->assertDatabaseCount('companies', 0);
    Http::assertSentCount(1);
})->with([
    'duplicate ids' => [sourcePage([array_replace(sourceCustomer(), ['stage' => 'CUSTOMER']), array_replace(sourceCustomer(), ['stage' => 'CUSTOMER'])])],
    'prospect' => [sourcePage([array_replace(sourceCustomer(), ['stage' => 'PROSPECT'])])],
    'invalid active flag' => [sourcePage([array_replace(sourceCustomer(), ['stage' => 'CUSTOMER', 'isinactive' => null])])],
    'invalid timestamp' => [sourcePage([array_replace(sourceCustomer(), ['stage' => 'CUSTOMER', 'updated_at' => 'unknown'])])],
    'empty continuation' => [sourcePage([], true)],
]);

it('skips busy and newer customer identities', function () {
    $busy = Company::factory()->create(['netsuite_id' => 16, 'name' => 'Busy']);
    $newer = Company::factory()->create(['netsuite_id' => 17, 'name' => 'Newer', 'netsuite_updated_at' => '2026-09-02 12:00:00']);
    Cache::lock('netsuite-sales-orders:16', 600)->get();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        array_replace(sourceCustomer(), ['stage' => 'CUSTOMER']),
        array_replace(sourceCustomer(), ['id' => '17', 'stage' => 'CUSTOMER']),
    ]))]);

    $counts = app(SyncCustomers::class)->handle();

    expect($counts['skipped_busy'])->toBe(1);
    expect($counts['skipped_stale'])->toBe(1);
    expect($busy->refresh()->name)->toBe('Busy');
    expect($newer->refresh()->name)->toBe('Newer');
    Http::assertSentCount(1);
});

it('refuses concurrent directory discovery without contacting NetSuite', function () {
    Cache::lock('netsuite-customer-discovery', 600)->get();

    $this->artisan('milkstool:sync-customers')->assertFailed();

    Http::assertNothingSent();
});

it('accepts same-second database lock refreshes during discovery', function () {
    config()->set('cache.default', 'database');
    $this->freezeSecond();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        array_replace(sourceCustomer(), ['stage' => 'CUSTOMER']),
    ]))]);

    $this->artisan('milkstool:sync-customers')->assertSuccessful();

    $this->assertDatabaseCount('companies', 1);
    Http::assertSentCount(1);
});

it('deduplicates discovery jobs and runs a serialized job on the customer queue', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        array_replace(sourceCustomer(), ['stage' => 'CUSTOMER']),
    ]))]);
    $this->artisan('milkstool:sync-customers', ['--queue' => true])->assertSuccessful();
    $this->artisan('milkstool:sync-customers', ['--queue' => true])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();

    Queue::connection('netsuite')->pop('customers')->fire();

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('companies', 1);
    Http::assertSentCount(1);
});

it('does not refresh orders for a customer deactivated after dispatch', function () {
    RefreshSalesOrders::dispatch(16);
    Company::factory()->create(['netsuite_id' => 16, 'is_active' => false]);

    Queue::connection('netsuite')->pop('sales-orders')->fire();

    $this->assertDatabaseCount('jobs', 0);
    Http::assertNothingSent();
});

it('retries transient discovery errors but fails permanent ones immediately', function (int $status, bool $retry) {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], $status)]);
    $job = (new RefreshCustomers)->withFakeQueueInteractions();

    if ($retry) {
        expect(fn () => $job->handle(app(SyncCustomers::class)))->toThrow(RequestException::class);
        $job->assertNotFailed();
    } else {
        $job->handle(app(SyncCustomers::class));
        $job->assertFailedWith(RequestException::class);
    }

    Http::assertSentCount(1);
})->with([[429, true], [503, true], [403, false]]);

it('rejects queued dry runs without contacting NetSuite', function () {
    $this->artisan('milkstool:sync-customers', ['--queue' => true, '--dry-run' => true])->assertFailed();

    $this->assertDatabaseCount('jobs', 0);
    Http::assertNothingSent();
});

it('schedules hourly discovery only when scheduled syncing is enabled', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'milkstool:sync-customers --queue'));
    $this->travelTo(now()->startOfHour());
    config()->set('netsuite-sync.scheduled', false);
    expect($event->filtersPass(app()))->toBeFalse();
    config()->set('netsuite-sync.scheduled', true);
    expect($event->filtersPass(app()))->toBeTrue();
    expect($event->isDue(app()))->toBeTrue();
    $this->travelTo(now()->addMinutes(15));
    expect($event->isDue(app()))->toBeFalse();
});

it('preserves negative and nullable NetSuite sales-rep references', function (?string $salesRepId) {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        array_replace(sourceCustomer(), ['stage' => 'CUSTOMER', 'sales_rep_id' => $salesRepId]),
    ]))]);

    $this->artisan('milkstool:sync-customers')->assertSuccessful();

    $company = Company::query()->sole();
    expect($company->sales_rep_id)->toBe($salesRepId === null ? null : -5);
    expect($company->raw_payload['sales_rep_id'])->toBe($salesRepId);
    Http::assertSentCount(1);
})->with(['negative' => ['-5'], 'unassigned' => [null]]);
