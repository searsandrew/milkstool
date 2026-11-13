<?php

use App\Actions\SyncSalesOrderDetails;
use App\Actions\SyncSalesOrders;
use App\Jobs\RefreshSalesOrderDetails;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

function detailOrder(): Transaction
{
    return Transaction::factory()->for(Company::factory()->create(['id' => 16]))->create([
        'id' => 101, 'type' => 'SalesOrd', 'currency_id' => 1, 'foreign_total' => '222.98',
        'netsuite_updated_at' => '2026-11-13 15:00:00',
    ]);
}

/** @return array<string, mixed> */
function detailRecord(): array
{
    return ['id' => '101', 'entity' => ['id' => '16'], 'currency' => ['id' => '1'],
        'lastModifiedDate' => '2026-11-13T15:00:00Z', 'subtotal' => 200, 'total' => 222.98, 'shippingCost' => 22.98,
        'billAddress' => "Billing\n123 Old Street", 'shipAddress' => "Shipping\n456 Dock", 'shipMethod' => ['id' => '410', 'refName' => 'UPS Ground']];
}

it('stores and exposes verified order addresses totals and fulfillment tracking', function () {
    $order = detailOrder();
    Http::fake([
        'https://netsuite.example/services/rest/record/v1/salesOrder/101*' => Http::response(detailRecord()),
        'https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(['items' => [['customer_id' => '16', 'tracking_number' => '1Z123']], 'hasMore' => false]),
    ]);

    app(SyncSalesOrderDetails::class)->handle(16, 101);

    expect($order->fresh()->raw_payload['sales_order_details'])->toMatchArray(['shipping_cost' => '22.98000000', 'subtotal' => '200.00000000', 'tracking_numbers' => ['1Z123']]);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/transactions/101')->assertOk()
        ->assertJsonPath('data.sales_order_details.billing_address', "Billing\n123 Old Street")
        ->assertJsonPath('data.sales_order_details.shipping_item_id', 410);
    Http::assertSentCount(2);
});

it('does not save details from another customer or a different order version', function (string $change) {
    $order = detailOrder();
    $record = detailRecord();
    if ($change === 'customer') {
        $record['entity']['id'] = '99';
    } else {
        $record['total'] = 999;
    }
    Http::fake(['https://netsuite.example/services/rest/record/v1/salesOrder/101*' => Http::response($record)]);

    expect(fn () => app(SyncSalesOrderDetails::class)->handle(16, 101))->toThrow($change === 'customer' ? ValidationException::class : RuntimeException::class);
    expect($order->fresh()->raw_payload)->not->toHaveKey('sales_order_details');
    Http::assertSentCount(1);
})->with(['customer', 'version']);

it('queues missing details only after customer authorization', function () {
    Queue::fake([RefreshSalesOrderDetails::class]);
    detailOrder();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:17']);
    $this->getJson('/api/v1/customers/16/transactions/101')->assertForbidden();
    Queue::assertNothingPushed();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/transactions/101')->assertOk()->assertJsonPath('data.sales_order_details', null);
    Queue::assertPushed(RefreshSalesOrderDetails::class, fn ($job) => $job->customerId === 16 && $job->orderId === 101);
});

it('retains saved details when tracking is incomplete', function () {
    $order = detailOrder();
    $order->update(['raw_payload' => ['sales_order_details' => ['tracking_numbers' => ['OLD']]]]);
    Http::fake([
        'https://netsuite.example/services/rest/record/v1/salesOrder/101*' => Http::response(detailRecord()),
        'https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(['items' => [], 'hasMore' => true]),
    ]);

    expect(fn () => app(SyncSalesOrderDetails::class)->handle(16, 101))->toThrow(RuntimeException::class);
    expect($order->fresh()->raw_payload['sales_order_details']['tracking_numbers'])->toBe(['OLD']);
});

it('preserves document details when source headers and lines are synced again', function () {
    $order = Transaction::factory()->for(Company::factory()->create(['id' => 16]))->create(['id' => 101, 'type' => 'SalesOrd']);
    $order->update(['raw_payload' => [...$order->raw_payload, 'sales_order_details' => ['tracking_numbers' => ['KEEP']]]]);
    fakeSalesOrderImport([sourceLine()]);

    app(SyncSalesOrders::class)->handle(16);

    expect($order->fresh()->raw_payload['sales_order_details']['tracking_numbers'])->toBe(['KEEP']);
});

it('refreshes tracking after fifteen minutes even if the order header is unchanged', function () {
    $this->freezeTime();
    Queue::fake([RefreshSalesOrderDetails::class]);
    $order = detailOrder();
    $order->update(['raw_payload' => ['sales_order_details' => ['header_updated_at' => '2026-11-13 15:00:00', 'synced_at' => now()->toIso8601String()]]]);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/transactions/101')->assertOk();
    Queue::assertNothingPushed();
    $this->travel(15)->minutes();

    $this->getJson('/api/v1/customers/16/transactions/101')->assertOk();

    Queue::assertPushed(RefreshSalesOrderDetails::class, 1);
});

it('hides details saved for an older order version while queuing a fresh copy', function () {
    Queue::fake([RefreshSalesOrderDetails::class]);
    $order = detailOrder();
    $order->update(['raw_payload' => ['sales_order_details' => ['header_updated_at' => '2026-11-12 15:00:00', 'synced_at' => now()->toIso8601String(), 'shipping_cost' => '99.00000000']]]);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);

    $this->getJson('/api/v1/customers/16/transactions/101')->assertOk()->assertJsonPath('data.sales_order_details', null);

    Queue::assertPushed(RefreshSalesOrderDetails::class, 1);
});
