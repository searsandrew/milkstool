<?php

namespace App\Services\NetSuite;

use App\Exceptions\ReceivableSyncInterrupted;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class CreditMemoSummarySource
{
    public function __construct(private NetSuiteRestClient $client) {}

    /**
     * @param  array<string, mixed>  $creditMemo
     * @return array{credit_applied: string, credit_remaining: string}
     */
    public function forCreditMemo(int $customerId, array $creditMemo): array
    {
        $record = $this->client->record('creditMemo')->get((int) $creditMemo['id'])->throw()->json();
        Validator::make((array) $record, [
            'id' => ['required', 'integer', 'in:'.$creditMemo['id']],
            'entity.id' => ['required', 'integer', 'in:'.$customerId],
            'currency.id' => ['required', 'integer', 'in:'.$creditMemo['currency_id']],
            'lastModifiedDate' => ['required', 'date'],
            'total' => ['required', 'numeric'],
            'applied' => ['required', 'numeric'],
            'unapplied' => ['required', 'numeric'],
        ])->validate();
        if (! BigDecimal::of((string) $record['total'])->isEqualTo(BigDecimal::of((string) $creditMemo['foreign_total'])->negated())
            || ! CarbonImmutable::parse($record['lastModifiedDate'])->utc()->startOfMinute()
                ->equalTo(CarbonImmutable::parse($creditMemo['updated_at'], 'UTC')->startOfMinute())) {
            throw new ReceivableSyncInterrupted('Credit memo summary and header do not describe the same source version. Retry.');
        }

        return [
            'credit_applied' => (string) BigDecimal::of((string) $record['applied'])->toScale(8, RoundingMode::Unnecessary),
            'credit_remaining' => (string) BigDecimal::of((string) $record['unapplied'])->toScale(8, RoundingMode::Unnecessary),
        ];
    }
}
