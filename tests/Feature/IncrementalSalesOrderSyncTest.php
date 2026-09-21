<?php

use App\Actions\SyncSalesOrders;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));
});

it('bootstraps an incremental checkpoint with a full history using the source clock', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))
        ->push(sourcePage([['current_time' => '2026-09-16 11:57:00']]))
        ->push(sourcePage([sourceOrder()]))->push(sourcePage([sourceLine()]))
        ->push(sourcePage([sourceOrder()]))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--incremental' => true])->assertSuccessful();

    $company = Company::query()->sole();
    expect($company->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 11:55:00');
    expect($company->sales_orders_full_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 12:00:00');
    expect($company->sales_orders_next_sync_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 18:00:00');
    $this->assertDatabaseCount('transaction_lines', 1);
    Http::assertSentCount(5);
});

it('updates an old order in an overlapping modification window without replacing unrelated history', function () {
    $company = Company::factory()->create(['netsuite_id' => 16,
        'sales_orders_checkpoint_at' => '2026-09-16 10:00:00', 'sales_orders_full_synced_at' => '2026-09-15 12:00:00']);
    $old = Transaction::factory()->for($company)->create(['netsuite_id' => 101]);
    TransactionLine::factory()->for($old)->create(['netsuite_line_id' => 8]);
    $unrelated = Transaction::factory()->for($company)->create(['netsuite_id' => 102]);
    $changed = sourceOrder(['updated_at' => '2026-09-16 09:55:00', 'status' => 'G']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([$changed]))->push(sourcePage([sourceLine(['quantity' => '-9'])]))
        ->push(sourcePage([$changed]))]);

    app(SyncSalesOrders::class)->handle(16, incremental: true);

    expect($old->refresh()->status)->toBe('G');
    expect($old->lines()->sole()->quantity)->toBe('-9.00000000');
    expect($old->lines()->sole()->netsuite_line_id)->toBe(1);
    $this->assertModelExists($unrelated);
    expect($company->refresh()->sales_orders_full_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-15 12:00:00');
    expect($company->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 11:58:00');
    Http::assertSent(fn ($request) => str_contains($request['q'], ">= TO_TIMESTAMP('2026-09-16 09:55:00'")
        && str_contains($request['q'], "<= TO_TIMESTAMP('2026-09-16 11:58:00'"));
});

it('advances an empty incremental window without deleting existing orders', function () {
    $company = Company::factory()->create(['netsuite_id' => 16,
        'sales_orders_checkpoint_at' => '2026-09-16 10:00:00', 'sales_orders_full_synced_at' => '2026-09-15 12:00:00']);
    $order = Transaction::factory()->for($company)->create();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([]))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--incremental' => true])->assertSuccessful();

    $this->assertModelExists($order);
    expect($company->refresh()->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 11:58:00');
    Http::assertSentCount(3);
});

it('retains its checkpoint and successful timestamp when an incremental page fails', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'sales_orders_synced_at' => '2026-09-16 10:02:00',
        'sales_orders_checkpoint_at' => '2026-09-16 10:00:00', 'sales_orders_full_synced_at' => '2026-09-15 12:00:00']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([sourceOrder(['updated_at' => '2026-09-16 11:00:00'])], true))->push([], 503)]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--incremental' => true])->assertFailed();

    expect($company->refresh()->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:00:00');
    expect($company->sales_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:02:00');
    expect($company->sales_orders_sync_error)->not->toBeNull();
});

it('performs a weekly full scan and refuses to hide a missing source order', function () {
    $company = Company::factory()->create(['netsuite_id' => 16,
        'sales_orders_checkpoint_at' => '2026-09-16 10:00:00', 'sales_orders_full_synced_at' => '2026-09-09 12:00:00']);
    $order = Transaction::factory()->for($company)->create();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([]))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--incremental' => true])->assertFailed();

    $this->assertModelExists($order);
    expect($company->refresh()->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:00:00');
    Http::assertSent(fn ($request) => str_contains($request['q'], 'AND id > 0 ORDER BY id') && ! str_contains($request['q'], 'TO_TIMESTAMP('));
});

it('rejects an order outside the source window without advancing the checkpoint', function () {
    $company = Company::factory()->create(['netsuite_id' => 16,
        'sales_orders_checkpoint_at' => '2026-09-16 10:00:00', 'sales_orders_full_synced_at' => '2026-09-15 12:00:00']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([sourceOrder()]))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--incremental' => true])->assertFailed();

    expect($company->refresh()->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:00:00');
    $this->assertDatabaseCount('transactions', 0);
});

it('refuses an unreliable source clock before fetching orders', function (array $clock) {
    $company = Company::factory()->create(['netsuite_id' => 16,
        'sales_orders_checkpoint_at' => '2026-09-16 10:00:00', 'sales_orders_full_synced_at' => '2026-09-15 12:00:00']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push($clock)]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--incremental' => true])->assertFailed();

    expect($company->refresh()->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:00:00');
    Http::assertSentCount(2);
})->with([
    'backwards' => [sourcePage([['current_time' => '2026-09-16 09:00:00']])],
    'missing' => [sourcePage([])],
    'invalid' => [sourcePage([['current_time' => 'invalid']])],
    'truncated' => [sourcePage([['current_time' => '2026-09-16 12:00:00']], true)],
]);

it('rejects resume combined with an incremental or queued import', function (string $option) {
    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--resume' => true, $option => true])->assertFailed();

    Http::assertNothingSent();
})->with(['--incremental', '--queue']);

it('rereads unchanged headers and repairs their lines during the weekly full scan', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'sales_orders_checkpoint_at' => '2026-09-16 10:00:00',
        'sales_orders_full_synced_at' => '2026-09-09 12:00:00']);
    $order = Transaction::factory()->for($company)->create(['netsuite_id' => 101, 'raw_payload' => sourceOrder()]);
    TransactionLine::factory()->for($order)->create(['netsuite_line_id' => 1, 'quantity' => '-100']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([sourceOrder()]))->push(sourcePage([sourceLine()]))->push(sourcePage([sourceOrder()]))]);

    app(SyncSalesOrders::class)->handle(16, incremental: true);

    expect($order->lines()->sole()->quantity)->toBe('-2.50000000');
    expect($company->refresh()->sales_orders_full_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 12:00:00');
    Http::assertSentCount(5);
});

it('keeps completed batches but replays their window after a later page fails', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'sales_orders_checkpoint_at' => '2026-09-16 10:00:00',
        'sales_orders_full_synced_at' => '2026-09-15 12:00:00']);
    $orders = array_map(fn (int $id): array => sourceOrder(['id' => (string) $id, 'updated_at' => '2026-09-16 11:00:00']), range(101, 150));
    $lines = array_map(fn (int $id): array => sourceLine(['transaction_id' => (string) $id]), range(101, 150));
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage($orders, true))->push(sourcePage($lines))->push(sourcePage($orders))->push([], 503)
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:01:00']]))
        ->push(sourcePage($orders))->push(sourcePage($lines))->push(sourcePage($orders))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--incremental' => true])->assertFailed();

    expect($company->refresh()->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:00:00');
    $this->assertDatabaseCount('transactions', 50);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => '16', '--incremental' => true])->assertSuccessful();

    $this->assertDatabaseCount('transactions', 50);
    $this->assertDatabaseCount('transaction_lines', 50);
    expect($company->refresh()->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 11:59:00');
    Http::assertSentCount(11);
});
