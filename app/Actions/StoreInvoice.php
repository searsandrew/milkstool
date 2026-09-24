<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreInvoice
{
    /**
     * Persist a validated, complete NetSuite invoice while the caller holds the customer invoice lock.
     *
     * @param  array<string, mixed>  $invoice
     * @param  list<array<string, mixed>>  $lines
     */
    public function handle(Company $company, array $invoice, array $lines): Transaction
    {
        $invoiceId = (int) $invoice['id'];

        return DB::transaction(function () use ($company, $invoiceId, $invoice, $lines): Transaction {
            $transaction = Transaction::query()->firstOrNew(['id' => $invoiceId]);

            if ($transaction->exists && ($transaction->company_id !== $company->id || $transaction->type !== 'CustInvc')) {
                throw new RuntimeException('This transaction belongs to another customer or transaction type.');
            }

            $attributes = array_intersect_key($invoice, array_flip([
                'type', 'number', 'purchase_order_number', 'transaction_date', 'status', 'status_name',
                'currency_id', 'total', 'foreign_total', 'memo', 'due_date', 'foreign_amount_paid', 'foreign_amount_unpaid',
            ]));
            foreach (['purchase_order_number', 'status_name', 'memo', 'due_date', 'foreign_amount_paid', 'foreign_amount_unpaid'] as $nullable) {
                $attributes[$nullable] = $invoice[$nullable] ?? null;
            }
            $transaction->fill([...$attributes, 'company_id' => $company->id,
                'netsuite_updated_at' => $invoice['updated_at'], 'synced_at' => now(), 'raw_payload' => $invoice]);
            $transaction->fillInvoiceDetails($invoice);
            $transaction->save();

            $lineIds = [];
            foreach ($lines as $line) {
                $lineIds[] = (int) $line['line_id'];
                $transaction->lines()->updateOrCreate(['netsuite_line_id' => $line['line_id']], [
                    'source_transaction_id' => $line['source_transaction_id'] ?? null,
                    'item_id' => $line['item_id'] ?? null, 'item_number' => $line['item_number'] ?? null,
                    'memo' => $line['memo'] ?? null, 'quantity' => $line['quantity'] ?? null,
                    'rate' => $line['rate'] ?? null, 'amount' => $line['amount'] ?? null,
                    'is_mainline' => $line['mainline'] === 'T', 'is_tax_line' => $line['taxline'] === 'T',
                    'is_discount_line' => $line['discount_line'] === 'T',
                    'line_type' => $line['line_type'] ?? null, 'raw_payload' => $line,
                ]);
            }
            $transaction->lines()->whereNotIn('netsuite_line_id', $lineIds)->delete();

            return $transaction;
        });
    }
}
