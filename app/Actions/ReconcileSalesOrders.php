<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\TransactionLine;
use App\Services\NetSuite\SalesOrderSource;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ReconcileSalesOrders
{
    public function __construct(private SalesOrderSource $source) {}

    /** @return list<array{currency_id: int, metric: string, source: string, local: string, matches: bool}> */
    public function handle(int $customerId): array
    {
        $company = Company::query()->where('id', $customerId)->first();

        if ($company === null) {
            throw new RuntimeException('Customer has not been imported. Run milkstool:sync-sales-orders first.');
        }

        $lock = Cache::lock('netsuite-sales-orders:'.$customerId, 600);

        if (! $lock->get()) {
            throw new RuntimeException('A sales-order sync or reconciliation is already running for this customer.');
        }

        try {
            $this->source->customer($customerId);
            $source = $this->source->controlTotals($customerId);
            $localOrders = $company->transactions()->where('type', 'SalesOrd')
                ->selectRaw('currency_id, COUNT(*) AS order_count, SUM(total) AS total, SUM(foreign_total) AS foreign_total')
                ->groupBy('currency_id')->toBase()->get()->keyBy('currency_id');
            $localLines = TransactionLine::query()
                ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
                ->where('transactions.company_id', $company->id)->where('transactions.type', 'SalesOrd')
                ->selectRaw('transactions.currency_id, COUNT(*) AS line_count, COUNT(quantity) AS quantity_count, COUNT(amount) AS amount_count, COALESCE(SUM(quantity), 0) AS quantity, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(CASE WHEN is_mainline = 0 THEN amount ELSE 0 END), 0) AS detail_amount')
                ->groupBy('transactions.currency_id')->toBase()->get()->keyBy('currency_id');
            $sourceOrders = collect($source['orders'])->keyBy('currency_id');
            $sourceLines = collect($source['lines'])->keyBy('currency_id');
            $currencies = $sourceOrders->keys()->merge($sourceLines->keys())
                ->merge($localOrders->keys())->merge($localLines->keys())->unique()->sort();
            $results = [];

            foreach ($currencies as $currency) {
                $sourceValues = array_merge($sourceOrders->get($currency, []), $sourceLines->get($currency, []));
                $localValues = array_merge((array) $localOrders->get($currency), (array) $localLines->get($currency));

                foreach (['order_count', 'line_count', 'quantity_count', 'amount_count', 'total', 'foreign_total', 'quantity', 'amount', 'detail_amount'] as $metric) {
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

            if (! $lock->isOwnedByCurrentProcess()) {
                throw new RuntimeException('The reconciliation lock expired. Retry the command.');
            }

            return $results;
        } finally {
            $lock->release();
        }
    }
}
