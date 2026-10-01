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
        'https://netsuite.example/services/rest/query/v1/suiteql*' => fakeCompleteInvoiceReads(Http::sequence()
            ->push(sourcePage([sourceInvoice($header)]))->push(sourcePage($lines))->push(sourcePage([sourceInvoice($header)])))]);
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

/** Provide complete invoice detail responses around a test's financial-query sequence. */
function fakeCompleteInvoiceReads(ResponseSequence $sequence, bool $clockFromSequence = false): Closure
{
    $headers = [];
    $lines = [];

    return function (Request $request) use ($sequence, $clockFromSequence, &$headers, &$lines): mixed {
        if (preg_match('~/record/v1/invoice/(\d+)~', $request->url(), $match)) {
            $id = (int) $match[1];
            if (! isset($headers[$id])) {
                throw new RuntimeException('Test did not provide an invoice header for the requested REST record.');
            }

            return Http::response(sourceInvoiceRecord($lines[$id] ?? [], $headers[$id]));
        }
        if (str_contains($request['q'] ?? '', 'itemfulfillmentpackage')) {
            return Http::response(sourcePage([]));
        }
        if (! $clockFromSequence && str_contains($request['q'] ?? '', 'CURRENT_TIMESTAMP')) {
            return Http::response(sourcePage([['current_time' => '2026-09-16 12:00:00']]));
        }

        return $sequence($request)->then(function ($response) use (&$headers, &$lines) {
            $payload = json_decode((string) $response->getBody(), true);
            foreach ($payload['items'] ?? [] as $row) {
                if (($row['type'] ?? null) === 'CustInvc' && isset($row['id'], $row['updated_at'])) {
                    $headers[(int) $row['id']] = $row;
                }
                if (isset($row['transaction_id'], $row['line_id'])) {
                    $lines[(int) $row['transaction_id']][(int) $row['line_id']] = $row;
                }
            }

            return $response;
        });
    };
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function invoiceHeaderTotals(array $overrides = []): array
{
    return array_replace(['currency_id' => '1', 'invoice_count' => '1', 'paid_count' => '1', 'unpaid_count' => '1',
        'foreign_amount_paid' => '20', 'foreign_amount_unpaid' => '5.12345678', 'total' => '25.12345678', 'foreign_total' => '25.12345678'], $overrides);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function invoiceLineTotals(array $overrides = []): array
{
    return array_replace(['currency_id' => '1', 'line_count' => '1', 'quantity_count' => '1', 'amount_count' => '1',
        'quantity' => '-2.5', 'amount' => '-25.12345678', 'detail_amount' => '-25.12345678'], $overrides);
}
