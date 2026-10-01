<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceInvoice(array $overrides = []): array
{
    return array_replace(sourceOrder(), ['id' => '1347', 'type' => 'CustInvc', 'number' => 'INV01',
        'due_date' => '2026-09-30', 'foreign_amount_paid' => '20', 'foreign_amount_unpaid' => '5.12345678'], $overrides);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceInvoiceLine(array $overrides = []): array
{
    return array_replace(sourceLine(), ['transaction_id' => '1347', 'source_transaction_id' => '101'], $overrides);
}

/** @param list<array<string, mixed>> $lines
 * @param  array<string, mixed>  $header
 */
function fakeSingleInvoice(array $lines, array $header = []): void
{
    Http::fake(['https://netsuite.example/services/rest/record/v1/invoice/1347*' => Http::response(sourceInvoiceRecord($lines, $header)),
        'https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
            ->push(sourcePage([sourceInvoice($header)]))->push(sourcePage($lines))->push(sourcePage([sourceInvoice($header)]))]);
}

function fakeEmptyCreditApplications(ResponseSequence $sequence): Closure
{
    return function (Request $request) use ($sequence): mixed {
        return str_contains($request['q'] ?? '', 'FROM NextTransactionLineLink')
            ? Http::response(sourcePage([])) : $sequence($request);
    };
}

/**
 * @param  list<array<string, mixed>>|null  $lines
 * @param  array<string, mixed>  $header
 * @return array<string, mixed>
 */
function sourceInvoiceRecord(?array $lines = null, array $header = []): array
{
    $invoice = sourceInvoice($header);
    $items = collect($lines ?? [sourceInvoiceLine()])->filter(fn (array $line): bool => $line['mainline'] !== 'T' && $line['taxline'] !== 'T'
        && $line['discount_line'] !== 'T' && ($line['kit_component'] ?? null) !== 'T' && ($line['item_type'] ?? null) !== 'ShipItem')->map(fn (array $line): array => [
            'line' => $line['line_id'], 'item' => ['id' => $line['item_id'] ?? null], 'quantityRemaining' => 3, 'quantityOrdered' => 5,
        ])->values()->all();

    return ['id' => $invoice['id'], 'entity' => ['id' => $invoice['customer_id']], 'currency' => ['id' => $invoice['currency_id']],
        'lastModifiedDate' => CarbonImmutable::parse($invoice['updated_at'], 'UTC')->toIso8601String(),
        'subtotal' => $invoice['foreign_total'], 'shippingCost' => 0, 'taxTotal' => 0, 'total' => $invoice['foreign_total'],
        'item' => ['items' => $items, 'totalResults' => count($items)]];
}
