<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\NetSuite\InvoiceTrackingSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncInvoiceTracking extends Command
{
    protected $signature = 'milkstool:sync-invoice-tracking {customer : NetSuite customer ID} {--invoice= : Refresh one invoice instead of all locally imported invoices}';

    protected $description = 'Refresh tracking numbers for shipments on invoice sales orders without reimporting financial history';

    public function handle(InvoiceTrackingSource $source): int
    {
        $customerId = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $invoiceId = $this->option('invoice') === null ? null : filter_var($this->option('invoice'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($customerId === false || $invoiceId === false) {
            $this->error('Customer and invoice IDs must be positive integers.');

            return self::FAILURE;
        }
        $customer = Company::find($customerId);
        if ($customer === null) {
            $this->error('Customer has not been imported.');

            return self::FAILURE;
        }
        $query = $customer->transactions()->where('type', 'CustInvc')->when($invoiceId !== null, fn ($query) => $query->where('id', $invoiceId));
        if ($invoiceId !== null && ! $query->exists()) {
            $this->error('Invoice has not been imported for this customer.');

            return self::FAILURE;
        }
        $lock = Cache::lock('netsuite-invoices:'.$customerId, 600);
        if (! $lock->get()) {
            $this->error('An invoice sync is already running for this customer.');

            return self::FAILURE;
        }
        try {
            $completed = 0;
            $query->chunkById(25, function ($invoices) use ($source, $customerId, $lock, &$completed): void {
                $ids = $invoices->modelKeys();
                $tracking = $source->fetch($customerId, $ids);
                if ($tracking !== $source->fetch($customerId, $ids)) {
                    throw new RuntimeException('Tracking changed during retrieval. Retry the command.');
                }
                if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('Invoice sync lock expired. Retry the command.');
                }
                DB::transaction(function () use ($invoices, $tracking): void {
                    foreach ($invoices as $invoice) {
                        $invoice->invoice_details = array_replace($invoice->invoice_details ?? [], [
                            'tracking_numbers' => $tracking[$invoice->id],
                            'tracking_scope' => 'related_sales_orders',
                            'tracking_synced_at' => now()->utc()->toIso8601String(),
                        ]);
                        $invoice->save();
                    }
                });
                $completed += $invoices->count();
                $this->line('Updated tracking for '.$completed.' invoices.');
            });
            $this->info('Invoice tracking refresh complete.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Tracking refresh failed; existing data was preserved for the failed batch. Check source permissions and retry.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
