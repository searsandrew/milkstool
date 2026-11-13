<?php

namespace App\Actions;

use App\Models\Transaction;
use App\Services\NetSuite\NetSuiteRestClient;
use App\Services\NetSuite\SuiteQlClient;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SyncSalesOrderDetails
{
    public function __construct(private NetSuiteRestClient $client, private SuiteQlClient $queries) {}

    public function handle(int $customerId, int $orderId): void
    {
        if ($customerId < 1 || $orderId < 1) {
            throw new RuntimeException('A customer and order are required.');
        }
        Cache::lock('sales-order-details:'.$orderId, 120)->block(1, function () use ($customerId, $orderId): void {
            $order = Transaction::query()->where('company_id', $customerId)->where('type', 'SalesOrd')->findOrFail($orderId);
            $record = $this->client->record('salesOrder')->get($orderId)->throw()->json();
            $rules = ['id' => ['required', 'integer', 'in:'.$orderId], 'entity.id' => ['required', 'integer', 'in:'.$customerId],
                'currency.id' => ['required', 'integer', 'in:'.$order->currency_id], 'lastModifiedDate' => ['required', 'date'],
                'total' => ['required', 'numeric'], 'billAddress' => ['nullable', 'string'], 'shipAddress' => ['nullable', 'string'],
                'shipMethod.id' => ['nullable', 'integer'], 'shipMethod.refName' => ['nullable', 'string'], 'shipDate' => ['nullable', 'date']];
            foreach (['subtotal', 'shippingCost', 'handlingCost', 'taxTotal', 'discountTotal'] as $field) {
                $rules[$field] = ['nullable', 'numeric'];
            }
            Validator::make((array) $record, $rules)->validate();
            if (! BigDecimal::of((string) $record['total'])->isEqualTo($order->foreign_total)
                || ! CarbonImmutable::parse($record['lastModifiedDate'])->utc()->startOfMinute()->equalTo($order->netsuite_updated_at->startOfMinute())) {
                throw new RuntimeException('Sales order details do not match the synced order version.');
            }
            $tracking = $this->queries->query(<<<SQL
                SELECT DISTINCT fulfillment.entity AS customer_id, itemfulfillmentpackage.packagetrackingnumber AS tracking_number
                FROM transaction fulfillment
                JOIN transactionline fulfillmentLine ON fulfillmentLine.transaction = fulfillment.id
                JOIN transaction salesOrder ON salesOrder.id = fulfillmentLine.createdfrom
                JOIN itemfulfillmentpackage ON itemfulfillmentpackage.itemfulfillment = fulfillment.id
                WHERE fulfillment.type = 'ItemShip' AND fulfillment.entity = {$customerId}
                    AND salesOrder.type = 'SalesOrd' AND salesOrder.entity = {$customerId} AND salesOrder.id = {$orderId}
                    AND itemfulfillmentpackage.packagetrackingnumber IS NOT NULL
                ORDER BY itemfulfillmentpackage.packagetrackingnumber
                SQL);
            if ($tracking['hasMore']) {
                throw new RuntimeException('Tracking results are incomplete; previous details were retained.');
            }
            Validator::make($tracking, ['items.*.customer_id' => ['required', 'integer', 'in:'.$customerId],
                'items.*.tracking_number' => ['required', 'string', 'max:255']])->validate();
            $details = ['billing_address' => $record['billAddress'] ?? null, 'shipping_address' => $record['shipAddress'] ?? null,
                'shipping_method' => $record['shipMethod']['refName'] ?? null, 'shipping_item_id' => isset($record['shipMethod']['id']) ? (int) $record['shipMethod']['id'] : null,
                'ship_date' => isset($record['shipDate']) ? substr($record['shipDate'], 0, 10) : null,
                'tracking_numbers' => array_values(array_unique(array_filter(array_map(trim(...), array_column($tracking['items'], 'tracking_number'))))),
                'synced_at' => now()->utc()->toIso8601String(), 'header_updated_at' => $order->netsuite_updated_at->utc()->format('Y-m-d H:i:s')];
            foreach (['subtotal' => 'subtotal', 'shippingCost' => 'shipping_cost', 'handlingCost' => 'handling_cost', 'taxTotal' => 'tax_total', 'discountTotal' => 'discount_total'] as $source => $target) {
                $details[$target] = isset($record[$source]) ? (string) BigDecimal::of((string) $record[$source])->toScale(8) : null;
            }
            DB::transaction(function () use ($order, $details): void {
                $current = Transaction::query()->lockForUpdate()->findOrFail($order->id);
                if ($current->company_id !== $order->company_id || $current->type !== 'SalesOrd'
                    || ! $current->netsuite_updated_at->equalTo($order->netsuite_updated_at)) {
                    throw new RuntimeException('Sales order changed while its details were retrieved.');
                }
                $current->update(['raw_payload' => [...$current->raw_payload, 'sales_order_details' => $details]]);
            });
        });
    }
}
