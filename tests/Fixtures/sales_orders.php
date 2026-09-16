<?php

use Illuminate\Support\Facades\Http;

function fakeNetSuiteConfiguration(): void
{
    config()->set('briar-rose', array_replace(config('briar-rose'), [
        'account' => 'testing', 'consumer_key' => 'testing', 'consumer_secret' => 'testing',
        'token_id' => 'testing', 'token_secret' => 'testing',
        'rest_base_url' => 'https://netsuite.example',
        'rest' => ['retries' => ['enabled' => false]],
    ]));
    Http::preventStrayRequests();
}

/** @return array<string, mixed> */
function sourceCustomer(): array
{
    return ['id' => '16', 'name' => 'Example Customer', 'account_number' => 'C-16',
        'sales_rep_id' => '974', 'isinactive' => 'F', 'updated_at' => '2026-09-01 12:00:00'];
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceOrder(array $overrides = []): array
{
    return array_replace(['id' => '101', 'customer_id' => '16', 'type' => 'SalesOrd',
        'number' => 'SO101', 'purchase_order_number' => 'PO-25', 'transaction_date' => '2026-08-01',
        'status' => 'B', 'status_name' => 'Sales Order : Pending Fulfillment', 'currency_id' => '1',
        'total' => '25.12345678', 'foreign_total' => '25.12345678', 'memo' => null,
        'updated_at' => '2026-09-01 12:00:00'], $overrides);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceLine(array $overrides = []): array
{
    return array_replace(['transaction_id' => '101', 'line_id' => '1', 'item_id' => '55',
        'item_number' => 'ITEM-55', 'quantity' => '-2.50000000', 'rate' => '10.04938271',
        'amount' => '-25.12345678', 'memo' => 'Historical line description', 'mainline' => 'F',
        'taxline' => 'F', 'discount_line' => 'F', 'line_type' => 'ITEM'], $overrides);
}

/** @param list<array<string, mixed>> $items
 * @return array{items: list<array<string, mixed>>, hasMore: bool}
 */
function sourcePage(array $items, bool $more = false): array
{
    return ['items' => $items, 'hasMore' => $more];
}

/** @param list<array<string, mixed>> $lines */
function fakeSalesOrderImport(array $lines): void
{
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))
        ->push(sourcePage([sourceOrder()]))
        ->push(sourcePage($lines))
        ->push(sourcePage([sourceOrder()]))]);
}
