<?php

use App\Services\NetSuite\CreditMemoSummarySource;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('reads source totals for applied partial and unused credits', function (string $applied, string $remaining) {
    Http::fake(['https://netsuite.example/services/rest/record/v1/creditMemo/1347' => Http::response(creditSummaryRecord(['applied' => $applied, 'unapplied' => $remaining]))]);

    $summary = app(CreditMemoSummarySource::class)->forCreditMemo(16, sourceInvoice(['foreign_total' => '-100']));

    expect($summary)->toBe(['credit_applied' => $applied.'.00000000', 'credit_remaining' => $remaining.'.00000000']);
    Http::assertSentCount(1);
})->with([['100', '0'], ['75', '25'], ['0', '100']]);

it('rejects missing invalid foreign or stale summaries', function (array $overrides) {
    Http::fake(['https://netsuite.example/services/rest/record/v1/creditMemo/1347' => Http::response(creditSummaryRecord($overrides))]);

    expect(fn () => app(CreditMemoSummarySource::class)->forCreditMemo(16, sourceInvoice(['foreign_total' => '-100'])))
        ->toThrow(Exception::class);
    Http::assertSentCount(1);
})->with([
    'unknown applied' => [['applied' => null]], 'invalid remaining' => [['unapplied' => 'bad']],
    'wrong memo' => [['id' => '99']], 'wrong customer' => [['entity' => ['id' => '17']]],
    'wrong currency' => [['currency' => ['id' => '2']]], 'different total' => [['total' => '99']],
    'different version' => [['lastModifiedDate' => '2020-01-01T00:00:00Z']],
]);
