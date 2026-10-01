<?php

namespace App\Console\Commands;

use App\Actions\SyncInvoiceTracking as SyncTracking;
use App\Models\Company;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class SyncInvoiceTracking extends Command
{
    protected $signature = 'milkstool:sync-invoice-tracking {customer : NetSuite customer ID} {--invoice= : Refresh one invoice instead of all locally imported invoices}';

    protected $description = 'Refresh tracking numbers for shipments on invoice sales orders without reimporting financial history';

    public function handle(SyncTracking $sync): int
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
        try {
            $completed = 0;
            $query->chunkById(25, function ($invoices) use ($sync, $customerId, &$completed): void {
                $completed += $sync->handle($customerId, $invoices->modelKeys());
                $this->line('Updated tracking for '.$completed.' invoices.');
            });
            $this->info('Invoice tracking refresh complete.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Tracking refresh failed; existing data was preserved for the failed batch. Check source permissions and retry.');

            return self::FAILURE;
        }
    }
}
