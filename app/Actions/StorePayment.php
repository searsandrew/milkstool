<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StorePayment
{
    /**
     * Persist a validated, complete NetSuite payment while the caller holds the customer payment lock.
     *
     * @param  array<string, mixed>  $payment
     * @param  list<array<string, mixed>>  $applications
     * @param  list<array<string, mixed>>  $lines
     */
    public function handle(Company $company, array $payment, array $lines, array $applications): Transaction
    {
        $paymentId = (int) $payment['id'];

        return DB::transaction(function () use ($company, $paymentId, $payment, $lines, $applications): Transaction {
            $transaction = Transaction::query()->firstOrNew(['netsuite_id' => $paymentId]);

            if ($transaction->exists && ($transaction->company_id !== $company->id || $transaction->type !== 'CustPymt')) {
                throw new RuntimeException('This transaction belongs to another customer or transaction type.');
            }

            $attributes = array_intersect_key($payment, array_flip([
                'type', 'number', 'purchase_order_number', 'transaction_date', 'status', 'status_name',
                'currency_id', 'total', 'foreign_total', 'memo', 'due_date', 'foreign_amount_paid', 'foreign_amount_unpaid',
            ]));
            foreach (['purchase_order_number', 'status_name', 'memo', 'due_date', 'foreign_amount_paid', 'foreign_amount_unpaid'] as $nullable) {
                $attributes[$nullable] = $payment[$nullable] ?? null;
            }
            $transaction->fill([...$attributes, 'company_id' => $company->id,
                'netsuite_updated_at' => $payment['updated_at'], 'synced_at' => now(), 'raw_payload' => $payment])->save();

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

            $applicationIds = [];
            foreach ($applications as $application) {
                $stored = $transaction->paymentApplications()->updateOrCreate([
                    'payment_line_id' => $application['payment_line_id'],
                    'target_netsuite_id' => $application['target_netsuite_id'],
                    'target_line_id' => $application['target_line_id'],
                ], [
                    'target_customer_id' => $application['target_customer_id'] ?? null,
                    'target_currency_id' => $application['target_currency_id'] ?? null,
                    'target_type' => $application['target_type'] ?? null,
                    'foreign_amount' => $application['foreign_amount'] ?? null,
                    'raw_payload' => $application,
                ]);
                $applicationIds[] = $stored->id;
            }
            $transaction->paymentApplications()->whereNotIn('id', $applicationIds)->delete();

            return $transaction;
        });
    }
}
