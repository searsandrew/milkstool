<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Transaction;
use App\Services\NetSuite\SalesOrderSource;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncSalesOrders
{
    public function __construct(private SalesOrderSource $source) {}

    /**
     * @param  (Closure(int, int): void)|null  $onProgress
     * @return array{orders: int, lines: int}
     */
    public function handle(int $customerId, ?Closure $onProgress = null): array
    {
        $lock = Cache::lock('netsuite-sales-orders:'.$customerId, 600);

        if (! $lock->get()) {
            throw new RuntimeException('A sales-order sync is already running for this customer.');
        }

        $company = null;

        try {
            $customer = $this->source->customer($customerId);
            $company = Company::query()->updateOrCreate(['netsuite_id' => $customerId], [
                'name' => $customer['name'],
                'account_number' => $customer['account_number'] ?? null,
                'sales_rep_id' => $customer['sales_rep_id'] ?? null,
                'is_active' => $customer['isinactive'] === 'F',
                'netsuite_updated_at' => $customer['updated_at'],
                'raw_payload' => $customer,
            ]);
            $company->forceFill(['sales_orders_sync_started_at' => now(), 'sales_orders_sync_error' => null])->save();
            $orders = 0;
            $lineCount = 0;

            foreach ($this->source->orders($customerId) as $order) {
                $lines = $this->source->lines($customerId, (int) $order['id']);
                $latest = $this->source->order($customerId, (int) $order['id']);

                if ($latest['updated_at'] !== $order['updated_at']) {
                    throw new RuntimeException('Sales order '.$order['id'].' changed during import. Retry the sync.');
                }

                if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('The sync lock expired. Retry the sync.');
                }

                $this->storeOrder($company, $latest, $lines);
                $orders++;
                $lineCount += count($lines);
                $onProgress?->__invoke($orders, $lineCount);
            }

            if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                throw new RuntimeException('The sync lock expired. Retry the sync.');
            }

            $localOrders = $company->transactions()->where('type', 'SalesOrd')->count();

            if ($localOrders !== $orders) {
                throw new RuntimeException("NetSuite returned {$orders} orders, but {$localOrders} are stored locally. Missing source orders were retained; reconciliation is required.");
            }

            $company->forceFill(['sales_orders_synced_at' => now(), 'sales_orders_sync_error' => null])->save();

            return ['orders' => $orders, 'lines' => $lineCount];
        } catch (Throwable $exception) {
            if ($company !== null && $lock->isOwnedByCurrentProcess()) {
                $company->forceFill(['sales_orders_sync_error' => 'Import failed. Last successful sync is unchanged. Rerun the sync command for details.'])->save();
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>  $lines
     */
    private function storeOrder(Company $company, array $order, array $lines): void
    {
        DB::transaction(function () use ($company, $order, $lines): void {
            $transaction = Transaction::query()->firstOrNew(['netsuite_id' => $order['id']]);

            if ($transaction->exists && $transaction->company_id !== $company->id) {
                throw new RuntimeException('The sales order is already associated with another customer; reconciliation is required.');
            }

            $transaction->fill([
                'company_id' => $company->id,
                'type' => $order['type'],
                'number' => $order['number'],
                'purchase_order_number' => $order['purchase_order_number'] ?? null,
                'transaction_date' => $order['transaction_date'],
                'status' => $order['status'],
                'status_name' => $order['status_name'] ?? null,
                'currency_id' => $order['currency_id'],
                'total' => $order['total'],
                'foreign_total' => $order['foreign_total'],
                'memo' => $order['memo'] ?? null,
                'netsuite_updated_at' => $order['updated_at'],
                'synced_at' => now(),
                'raw_payload' => $order,
            ])->save();

            $lineIds = [];

            foreach ($lines as $line) {
                $lineIds[] = (int) $line['line_id'];
                $transaction->lines()->updateOrCreate(['netsuite_line_id' => $line['line_id']], [
                    'item_id' => $line['item_id'] ?? null,
                    'item_number' => $line['item_number'] ?? null,
                    'memo' => $line['memo'] ?? null,
                    'quantity' => $line['quantity'] ?? null,
                    'rate' => $line['rate'] ?? null,
                    'amount' => $line['amount'] ?? null,
                    'is_mainline' => $line['mainline'] === 'T',
                    'is_tax_line' => $line['taxline'] === 'T',
                    'is_discount_line' => $line['discount_line'] === 'T',
                    'line_type' => $line['line_type'] ?? null,
                    'raw_payload' => $line,
                ]);
            }

            $transaction->lines()->whereNotIn('netsuite_line_id', $lineIds)->delete();
        });
    }
}
