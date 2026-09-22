<?php

use App\Actions\SyncSalesOrders;
use App\Exceptions\SalesOrderSyncInterrupted;
use App\Jobs\RefreshSubmittedOrder;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeSecond();
    $this->company = Company::factory()->create(['netsuite_id' => 16]);
});

it('requires authentication an explicit refresh permission and a customer grant', function () {
    $this->postJson('/api/v1/customers/16/order-refreshes', ['sales_order_id' => 101])->assertUnauthorized();
    foreach ([['transactions:read', 'customer:16'], ['activity:write', 'customer:16'], ['orders:refresh', 'customer:17'], ['orders:refresh']] as $abilities) {
        Sanctum::actingAs(ApiClient::factory()->create(), $abilities);
        $this->postJson('/api/v1/customers/16/order-refreshes', ['sales_order_id' => 101])->assertForbidden();
    }
    $this->assertDatabaseCount('jobs', 0);
    expect($this->company->refresh()->portal_last_active_at)->toBeNull();
    Http::assertNothingSent();
});

it('queues a deduplicated refresh without contacting NetSuite in the request', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['orders:refresh', 'customer:16']);

    $this->postJson('/api/v1/customers/16/order-refreshes', ['sales_order_id' => 101])->assertAccepted()
        ->assertJsonPath('data.sales_order_id', 101)->assertJsonPath('data.status', 'refresh_requested');
    $this->postJson('/api/v1/customers/16/order-refreshes', ['sales_order_id' => 101])->assertAccepted();

    $this->assertDatabaseCount('jobs', 1);
    expect($this->company->refresh()->portal_last_active_at->eq(now()))->toBeTrue();
    Http::assertNothingSent();
});

it('rejects inactive customers and known documents belonging to another customer or type', function (string $case) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['orders:refresh', 'customers:all']);
    if ($case === 'inactive') {
        $this->company->forceFill(['is_active' => false])->save();
    } else {
        $owner = $case === 'foreign' ? Company::factory()->create() : $this->company;
        Transaction::factory()->for($owner)->create(['netsuite_id' => 101, 'type' => $case === 'foreign' ? 'SalesOrd' : 'CustInvc']);
    }

    $this->postJson('/api/v1/customers/16/order-refreshes', ['sales_order_id' => 101])->assertStatus($case === 'inactive' ? 409 : 404);

    $this->assertDatabaseCount('jobs', 0);
    Http::assertNothingSent();
})->with(['inactive', 'foreign', 'wrong_type']);

it('validates the submitted NetSuite order ID before queuing', function (array $payload) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['orders:refresh', 'customer:16']);
    $this->postJson('/api/v1/customers/16/order-refreshes', $payload)->assertUnprocessable();
    $this->assertDatabaseCount('jobs', 0);
    Http::assertNothingSent();
})->with([[[]], [['sales_order_id' => 0]], [['sales_order_id' => -1]], [['sales_order_id' => '1 OR 1=1']], [['sales_order_id' => '999999999999999999999999']]]);

it('retries a not yet visible order then imports it without advancing history checkpoints and requests a balance refresh', function () {
    $this->company->forceFill(['sales_orders_synced_at' => '2026-09-01 12:00:00', 'sales_orders_checkpoint_at' => '2026-09-01 11:58:00'])->save();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([]))->push(sourcePage([sourceOrder()]))->push(sourcePage([sourceLine()]))->push(sourcePage([sourceOrder()]))]);
    RefreshSubmittedOrder::dispatch(16, 101);
    Queue::connection('netsuite')->pop('sales-orders')->fire();
    $this->assertDatabaseCount('transactions', 0);
    $this->assertDatabaseCount('jobs', 1);
    $this->travel(61)->seconds();

    Queue::connection('netsuite')->pop('sales-orders')->fire();

    $this->assertDatabaseCount('transaction_lines', 1);
    expect(Transaction::query()->sole()->netsuite_id)->toBe(101);
    expect($this->company->refresh()->sales_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($this->company->sales_orders_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 11:58:00');
    $this->assertDatabaseHas('jobs', ['queue' => 'balances']);
    $this->assertDatabaseMissing('jobs', ['queue' => 'sales-orders']);
    Http::assertSentCount(4);
});

it('stops polling when the fixed retry window expires', function () {
    $job = (new RefreshSubmittedOrder(16, 101))->withFakeQueueInteractions();
    $deadline = $job->retryUntil();
    $this->travel(16)->minutes();

    $job->handle(app(SyncSalesOrders::class));

    $job->assertFailedWith(RuntimeException::class);
    expect($job->retryUntil()->eq($deadline))->toBeTrue();
    Http::assertNothingSent();
});

it('retains stored data and retries when the source order changes during import', function () {
    $existing = Transaction::factory()->for($this->company)->create(['netsuite_id' => 101]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceOrder()]))->push(sourcePage([sourceLine()]))->push(sourcePage([sourceOrder(['total' => '999'])]))]);

    expect(fn () => app(SyncSalesOrders::class)->syncOrder(16, 101))->toThrow(SalesOrderSyncInterrupted::class);

    expect($existing->refresh()->total)->toBe('100.00000000');
    $this->assertDatabaseCount('transaction_lines', 0);
});

it('preserves source ownership and stored document type boundaries', function (bool $wrongSource) {
    Transaction::factory()->for($this->company)->create(['netsuite_id' => 101, 'type' => 'CustInvc']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceOrder(['customer_id' => $wrongSource ? '17' : '16'])]))
        ->push(sourcePage([sourceLine()]))->push(sourcePage([sourceOrder()]))]);
    $job = (new RefreshSubmittedOrder(16, 101))->withFakeQueueInteractions();

    $job->handle(app(SyncSalesOrders::class));

    $job->assertFailed();
    expect(Transaction::query()->sole()->type)->toBe('CustInvc');
    $this->assertDatabaseCount('transaction_lines', 0);
})->with([true, false]);

it('retries transient NetSuite errors and fails permission errors', function (int $status) {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], $status)]);
    $job = (new RefreshSubmittedOrder(16, 101))->withFakeQueueInteractions();

    if ($status === 403) {
        $job->handle(app(SyncSalesOrders::class));
        $job->assertFailedWith(RequestException::class);
    } else {
        expect(fn () => $job->handle(app(SyncSalesOrders::class)))->toThrow(RequestException::class);
        $job->assertNotFailed();
    }
    $this->assertDatabaseCount('transactions', 0);
})->with([403, 429, 503]);

it('waits for existing customer syncs and skips customers deactivated after dispatch', function () {
    $lock = Cache::lock('netsuite-sales-orders:16', 600);
    $lock->get();
    $job = (new RefreshSubmittedOrder(16, 101))->withFakeQueueInteractions();
    expect(fn () => $job->handle(app(SyncSalesOrders::class)))->toThrow(SalesOrderSyncInterrupted::class);
    $lock->release();
    $this->company->forceFill(['is_active' => false])->save();
    $job->handle(app(SyncSalesOrders::class));
    $job->assertNotFailed();
    Http::assertNothingSent();
});

it('grants submitted order refreshes only when explicitly requested for customers', function () {
    $this->artisan('milkstool:token:issue', ['client' => 'portal', '--orders' => true])->assertFailed();
    Artisan::call('milkstool:token:issue', ['client' => 'portal', '--customer' => ['16'], '--orders' => true]);
    expect(ApiClient::query()->where('name', 'portal')->sole()->tokens()->sole()->abilities)->toContain('orders:refresh', 'customer:16');
    Artisan::call('milkstool:token:issue', ['client' => 'reader', '--customer' => ['16']]);
    expect(ApiClient::query()->where('name', 'reader')->sole()->tokens()->sole()->abilities)->not->toContain('orders:refresh');
});
