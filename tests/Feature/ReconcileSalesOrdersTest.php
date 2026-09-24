<?php

use App\Actions\ReconcileSalesOrders;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

/** @param array<string, mixed> $orderOverrides
 * @param  array<string, mixed>  $lineOverrides
 */
function fakeControlTotals(array $orderOverrides = [], array $lineOverrides = []): void
{
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))
        ->push(sourcePage([array_replace(['currency_id' => '1', 'order_count' => '1', 'total' => '100', 'foreign_total' => '100'], $orderOverrides)]))
        ->push(sourcePage([array_replace(['currency_id' => '1', 'line_count' => '1', 'quantity_count' => '1', 'amount_count' => '1', 'quantity' => '-2', 'amount' => '-100', 'detail_amount' => '-100'], $lineOverrides)]))]);
}

it('matches signed totals without mixing customers, document types, or currencies', function () {
    $company = Company::factory()->create(['id' => 16]);
    $order = Transaction::factory()->for($company)->create();
    TransactionLine::factory()->for($order)->create();
    Transaction::factory()->for($company)->create(['type' => 'CustInvc']);
    Transaction::factory()->create();
    fakeControlTotals();

    $results = app(ReconcileSalesOrders::class)->handle(16);

    expect($results)->toHaveCount(9);
    expect(collect($results)->pluck('matches')->unique()->all())->toBe([true]);
    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], "WHERE entity = 16 AND type = 'SalesOrd'")
        && str_contains($request['q'], 'GROUP BY currency'));
});

it('reports monetary differences without modifying local data or sync timestamps', function () {
    $company = Company::factory()->create(['id' => 16, 'sales_orders_synced_at' => '2026-09-01 12:00:00']);
    $order = Transaction::factory()->for($company)->create();
    TransactionLine::factory()->for($order)->create();
    fakeControlTotals(['total' => '100.00000001']);

    $this->artisan('milkstool:reconcile-sales-orders', ['customer' => '16'])->assertFailed();

    expect($order->refresh()->total)->toBe('100.00000000');
    expect($company->refresh()->sales_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
});

it('detects null quantities even when their summed value is unchanged', function () {
    $company = Company::factory()->create(['id' => 16]);
    $order = Transaction::factory()->for($company)->create();
    TransactionLine::factory()->for($order)->create(['quantity' => null]);
    fakeControlTotals([], ['quantity' => '0']);

    $results = app(ReconcileSalesOrders::class)->handle(16);

    expect(collect($results)->where('matches', false)->pluck('metric')->all())->toBe(['quantity_count']);
});

it('reports a currency present only in local data', function () {
    $company = Company::factory()->create(['id' => 16]);
    $order = Transaction::factory()->for($company)->create(['currency_id' => 2]);
    TransactionLine::factory()->for($order)->create();
    fakeControlTotals();

    $results = app(ReconcileSalesOrders::class)->handle(16);

    expect(collect($results)->pluck('currency_id')->unique()->values()->all())->toBe([1, 2]);
    expect(collect($results)->where('metric', 'order_count')->pluck('matches')->all())->toBe([false, false]);
});

it('accepts an empty history on both sides', function () {
    Company::factory()->create(['id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))->push(sourcePage([]))]);

    $this->artisan('milkstool:reconcile-sales-orders', ['customer' => '16'])->assertSuccessful();
});

it('rejects incomplete or malformed source totals', function (array $totals, bool $more) {
    Company::factory()->create(['id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage($totals, $more))->push(sourcePage([]))]);

    $this->artisan('milkstool:reconcile-sales-orders', ['customer' => '16'])->assertFailed();
})->with([
    'truncated' => [[['currency_id' => 1]], true],
    'missing amount' => [[['currency_id' => 1, 'order_count' => 1]], false],
]);

it('refuses reconciliation while a sync is running', function () {
    Company::factory()->create(['id' => 16]);
    Cache::lock('netsuite-sales-orders:16', 600)->get();

    $this->artisan('milkstool:reconcile-sales-orders', ['customer' => '16'])->assertFailed();

    Http::assertNothingSent();
});

it('rejects an unknown local customer without contacting NetSuite', function () {
    $this->artisan('milkstool:reconcile-sales-orders', ['customer' => '16'])->assertFailed();

    Http::assertNothingSent();
});

it('rejects an invalid customer identifier', function () {
    $this->artisan('milkstool:reconcile-sales-orders', ['customer' => '16 OR 1=1'])->assertFailed();

    Http::assertNothingSent();
});

it('reports NetSuite request failures without claiming a match', function () {
    Company::factory()->create(['id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], 403)]);

    $this->artisan('milkstool:reconcile-sales-orders', ['customer' => '16'])
        ->expectsOutput('NetSuite returned HTTP 403. Reconciliation did not complete.')->assertFailed();
});

it('reports connection failures without claiming a match', function () {
    Company::factory()->create(['id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::failedConnection()]);

    $this->artisan('milkstool:reconcile-sales-orders', ['customer' => '16'])
        ->expectsOutput('Could not connect to NetSuite. Reconciliation did not complete.')->assertFailed();
});
