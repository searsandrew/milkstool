<?php

use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('marks a reconciled customer complete and skips it on subsequent runs', function () {
    $company = Company::factory()->create(['id' => 16]);
    Company::factory()->create(['id' => 17, 'is_active' => false]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-21 12:00:00']]))
        ->push(sourcePage([]))->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))->push(sourcePage([]))]);

    $this->artisan('milkstool:backfill-sales-orders', ['--limit' => '1'])->assertSuccessful();
    expect($company->refresh()->sales_orders_backfilled_at)->not->toBeNull();
    $this->artisan('milkstool:backfill-sales-orders')->expectsOutput('Backfill complete: 0 customers imported and reconciled.')->assertSuccessful();
    Http::assertSentCount(6);
});

it('stops before the next customer when import fails and leaves the failed customer resumable', function () {
    $company = Company::factory()->create(['id' => 16]);
    $next = Company::factory()->create(['id' => 17]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], 403)]);

    $this->artisan('milkstool:backfill-sales-orders')->assertFailed();

    expect($company->refresh()->sales_orders_backfilled_at)->toBeNull();
    expect($next->refresh()->sales_orders_synced_at)->toBeNull();
    Http::assertSentCount(1);
    expect(Cache::lock('netsuite-sales-order-backfill', 86400)->get())->toBeTrue();
});

it('refuses to mark a successful import complete when reconciliation differs', function () {
    $company = Company::factory()->create(['id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-21 12:00:00']]))
        ->push(sourcePage([]))->push(sourcePage([sourceCustomer()]))
        ->push(sourcePage([['currency_id' => '1', 'order_count' => '1', 'total' => '100', 'foreign_total' => '100']]))
        ->push(sourcePage([]))]);

    $this->artisan('milkstool:backfill-sales-orders')->assertFailed();

    expect($company->refresh()->sales_orders_synced_at)->not->toBeNull();
    expect($company->sales_orders_backfilled_at)->toBeNull();
    Http::assertSentCount(6);
});

it('previews only the bounded batch in source ID order without contacting NetSuite', function () {
    Company::factory()->create(['id' => 17]);
    Company::factory()->create(['id' => 16]);

    $this->artisan('milkstool:backfill-sales-orders', ['--limit' => '1', '--dry-run' => true])
        ->expectsOutput('Backfill customer 16')->doesntExpectOutput('Backfill customer 17')->assertSuccessful();

    expect(Company::query()->whereNotNull('sales_orders_backfilled_at')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects invalid batch limits', function (string $limit) {
    $this->artisan('milkstool:backfill-sales-orders', ['--limit' => $limit])->assertFailed();
    Http::assertNothingSent();
})->with(['0', '-1', '1001', 'abc']);

it('refuses concurrent backfills', function () {
    Cache::lock('netsuite-sales-order-backfill', 86400)->get();
    $this->artisan('milkstool:backfill-sales-orders')->assertFailed();
    Http::assertNothingSent();
});
