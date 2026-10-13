<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreReturnAuthorization
{
    /**
     * Persist a validated complete RMA while the caller holds the customer RMA lock.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function handle(Company $company, array $header, array $lines): Transaction
    {
        return DB::transaction(function () use ($company, $header, $lines): Transaction {
            $transaction = Transaction::query()->firstOrNew(['id' => (int) $header['id']]);
            if ($transaction->exists && ($transaction->company_id !== $company->id || $transaction->type !== 'RtnAuth')) {
                throw new RuntimeException('This RMA ID belongs to another customer or transaction type.');
            }
            $attributes = array_intersect_key($header, array_flip([
                'type', 'number', 'external_id', 'transaction_date', 'status', 'status_name', 'currency_id', 'total', 'foreign_total', 'memo',
            ]));
            foreach (['external_id', 'status_name', 'memo'] as $nullable) {
                $attributes[$nullable] = $header[$nullable] ?? null;
            }
            $transaction->fill([...$attributes, 'company_id' => $company->id, 'netsuite_updated_at' => $header['updated_at'],
                'synced_at' => now(), 'raw_payload' => $header])->save();
            $ids = [];
            foreach ($lines as $line) {
                $ids[] = $line['line_id'];
                $transaction->lines()->updateOrCreate(['netsuite_line_id' => $line['line_id']], [
                    ...array_intersect_key($line, array_flip(['source_transaction_id', 'source_transaction_line_id',
                        'item_id', 'item_number', 'memo', 'quantity', 'rate', 'amount'])),
                    'is_mainline' => false, 'is_tax_line' => false, 'is_discount_line' => false,
                    'line_type' => null, 'raw_payload' => $line,
                ]);
            }
            $transaction->lines()->whereNotIn('netsuite_line_id', $ids)->delete();

            return $transaction;
        });
    }
}
