<?php

use App\Actions\SyncSalesOrders;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('imports sales orders with exact source signs, nullable quantities, and distinct source dates', function () {
    $this->travelTo(now()->startOfSecond());
    fakeSalesOrderImport([sourceLine(), sourceLine(['line_id' => '2', 'quantity' => null, 'item_id' => null])]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])->assertSuccessful();

    $this->assertDatabaseCount('companies', 1);
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('transaction_lines', 2);
    $transaction = Transaction::query()->sole();
    expect($transaction->total)->toBe('25.12345678');
    expect($transaction->transaction_date->format('Y-m-d'))->toBe('2026-08-01');
    expect($transaction->netsuite_updated_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($transaction->lines()->where('netsuite_line_id', 1)->sole()->quantity)->toBe('-2.50000000');
    expect($transaction->lines()->where('netsuite_line_id', 2)->sole()->quantity)->toBeNull();
    expect(Company::query()->sole()->sales_orders_synced_at->equalTo(now()))->toBeTrue();
    Http::assertSentCount(4);
});

it('reimports without duplicates and removes lines no longer present in a complete source order', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceOrder()]))
        ->push(sourcePage([sourceLine(), sourceLine(['line_id' => '2'])]))->push(sourcePage([sourceOrder()]))
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceOrder()]))
        ->push(sourcePage([sourceLine(['quantity' => '-9'])]))->push(sourcePage([sourceOrder()]))]);
    app(SyncSalesOrders::class)->handle(16);

    app(SyncSalesOrders::class)->handle(16);

    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('transaction_lines', 1);
    expect(TransactionLine::query()->sole()->quantity)->toBe('-9.00000000');
});

it('retains the previous order and successful timestamp when a later line page fails', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'sales_orders_synced_at' => '2026-08-15 12:00:00']);
    $order = Transaction::factory()->for($company)->create(['netsuite_id' => 101]);
    $line = TransactionLine::factory()->for($order)->create(['netsuite_line_id' => 8]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceOrder()]))
        ->push(sourcePage([sourceLine()], true))->push([], 503)]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])
        ->expectsOutput('NetSuite returned HTTP 503. The import did not complete.')->assertFailed();

    $this->assertModelExists($line);
    expect($order->refresh()->total)->toBe('100.00000000');
    expect($company->refresh()->sales_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-08-15 12:00:00');
    expect($company->sales_orders_sync_error)->not->toBeNull();
    expect(Cache::lock('netsuite-sales-orders:16', 600)->get())->toBeTrue();
});

it('refuses an order that changed while its lines were being fetched', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceOrder()]))
        ->push(sourcePage([sourceLine()]))
        ->push(sourcePage([sourceOrder(['updated_at' => '2026-09-01 12:00:01'])]))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
    expect(Company::query()->sole()->sales_orders_synced_at)->toBeNull();
});

it('does not move an existing order between customers automatically', function () {
    $other = Company::factory()->create(['netsuite_id' => 17]);
    $order = Transaction::factory()->for($other)->create(['netsuite_id' => 101]);
    fakeSalesOrderImport([sourceLine()]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])->assertFailed();

    expect($order->refresh()->company_id)->toBe($other->id);
    $this->assertDatabaseCount('transaction_lines', 0);
});

it('retains missing source orders and reports a reconciliation mismatch', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $order = Transaction::factory()->for($company)->create(['netsuite_id' => 100]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])->assertFailed();

    $this->assertModelExists($order);
    expect($company->refresh()->sales_orders_synced_at)->toBeNull();
});

it('records a successful empty history for a customer without orders', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])->assertSuccessful();

    expect(Company::query()->sole()->sales_orders_synced_at)->not->toBeNull();
    $this->assertDatabaseCount('transactions', 0);
});

it('refuses concurrent syncs without making NetSuite requests', function () {
    Cache::lock('netsuite-sales-orders:16', 600)->get();

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])
        ->expectsOutput('A sales-order sync is already running for this customer.')->assertFailed();

    Http::assertNothingSent();
});

it('rejects invalid customer IDs before contacting NetSuite', function (string $id) {
    $this->artisan('milkstool:sync-sales-orders', ['customer' => $id])
        ->expectsOutput('Customer must be a positive NetSuite internal ID.')->assertFailed();

    Http::assertNothingSent();
})->with(['0', '-1', '16 OR 1=1']);

it('reports connection failures without creating a customer', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::failedConnection()]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])
        ->expectsOutput('Could not connect to NetSuite. The import did not complete.')->assertFailed();

    $this->assertDatabaseCount('companies', 0);
});

it('accepts a database lock refresh in the same second when ownership is retained', function () {
    config()->set('cache.default', 'database');
    $this->travelTo(now()->startOfSecond());
    fakeSalesOrderImport([sourceLine()]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])->assertSuccessful();

    expect(Company::query()->sole()->sales_orders_synced_at)->not->toBeNull();
});

it('resumes matching saved orders without downloading their lines again', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceOrder()]))
        ->push(sourcePage([sourceLine()]))->push(sourcePage([sourceOrder()]))
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceOrder()]))]);
    app(SyncSalesOrders::class)->handle(16);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--resume' => true])->assertSuccessful();

    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('transaction_lines', 1);
    Http::assertSentCount(6);
});

it('fetches multiple orders and their lines in one bounded batch', function () {
    $second = sourceOrder(['id' => '102', 'number' => 'SO102']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceOrder(), $second]))
        ->push(sourcePage([sourceLine(), sourceLine(['transaction_id' => '102'])]))
        ->push(sourcePage([sourceOrder(), $second]))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16'])->assertSuccessful();

    $this->assertDatabaseCount('transactions', 2);
    $this->assertDatabaseCount('transaction_lines', 2);
    Http::assertSentCount(4);
});
