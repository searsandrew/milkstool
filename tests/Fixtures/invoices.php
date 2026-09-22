<?php

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
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceInvoice($header)]))->push(sourcePage($lines))->push(sourcePage([sourceInvoice($header)]))]);
}

function fakeEmptyCreditApplications(ResponseSequence $sequence): Closure
{
    return function (Request $request) use ($sequence): mixed {
        return str_contains($request['q'] ?? '', 'FROM NextTransactionLineLink')
            ? Http::response(sourcePage([])) : $sequence($request);
    };
}
