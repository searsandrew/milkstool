<?php

namespace App\Services\NetSuite;

use App\Models\Company;
use App\Models\TransactionLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class InvoiceReconciliation
{
    public function __construct(private InvoiceSource $source) {}

    /**
     * Compare aggregates while the caller holds the customer invoice lock.
     *
     * @return list<array{currency_id: int, metric: string, source: string, local: string, matches: bool}>
     */
    public function compare(Company $company): array
    {
        $source = $this->source->controlTotals((int) $company->id);
        $localInvoices = $company->transactions()->where('type', 'CustInvc')
            ->selectRaw('currency_id, COUNT(*) AS invoice_count, COUNT(foreign_amount_paid) AS paid_count, COUNT(foreign_amount_unpaid) AS unpaid_count, COALESCE(SUM(foreign_amount_paid), 0) AS foreign_amount_paid, COALESCE(SUM(foreign_amount_unpaid), 0) AS foreign_amount_unpaid, SUM(total) AS total, SUM(foreign_total) AS foreign_total')
            ->groupBy('currency_id')->toBase()->get()->keyBy('currency_id');
        $localLines = TransactionLine::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
            ->where('transactions.company_id', $company->id)->where('transactions.type', 'CustInvc')
            ->selectRaw('transactions.currency_id, COUNT(*) AS line_count, COUNT(quantity) AS quantity_count, COUNT(amount) AS amount_count, COALESCE(SUM(quantity), 0) AS quantity, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(CASE WHEN is_mainline = 0 THEN amount ELSE 0 END), 0) AS detail_amount')
            ->groupBy('transactions.currency_id')->toBase()->get()->keyBy('currency_id');
        $sourceInvoices = collect($source['invoices'])->keyBy('currency_id');
        $sourceLines = collect($source['lines'])->keyBy('currency_id');
        $currencies = $sourceInvoices->keys()->merge($sourceLines->keys())
            ->merge($localInvoices->keys())->merge($localLines->keys())->unique()->sort();
        $results = [];

        foreach ($currencies as $currency) {
            $sourceValues = array_merge($sourceInvoices->get($currency, []), $sourceLines->get($currency, []));
            $localValues = array_merge((array) $localInvoices->get($currency), (array) $localLines->get($currency));

            foreach (['paid_count', 'unpaid_count', 'foreign_amount_paid', 'foreign_amount_unpaid', 'invoice_count', 'line_count', 'quantity_count', 'amount_count', 'total', 'foreign_total', 'quantity', 'amount', 'detail_amount'] as $metric) {
                $sourceValue = BigDecimal::of((string) ($sourceValues[$metric] ?? '0'))->toScale(8, RoundingMode::HalfUp);
                $localValue = BigDecimal::of((string) ($localValues[$metric] ?? '0'))->toScale(8, RoundingMode::HalfUp);
                $results[] = [
                    'currency_id' => (int) $currency,
                    'metric' => $metric,
                    'source' => (string) $sourceValue,
                    'local' => (string) $localValue,
                    'matches' => $sourceValue->isEqualTo($localValue),
                ];
            }
        }

        return $results;
    }
}
