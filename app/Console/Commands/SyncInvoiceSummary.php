<?php

namespace App\Console\Commands;

use App\Actions\SyncInvoice;
use App\Models\Transaction;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class SyncInvoiceSummary extends Command
{
    protected $signature = 'milkstool:sync-invoice-summary {customer} {invoice}';

    protected $description = 'Refresh one complete invoice including its NetSuite summary and quantities';

    public function handle(SyncInvoice $sync): int
    {
        $customerId = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $invoiceId = filter_var($this->argument('invoice'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($customerId === false || $invoiceId === false) {
            $this->error('Customer and invoice IDs must be positive integers.');

            return self::FAILURE;
        }
        try {
            $invoice = Transaction::query()->where('company_id', $customerId)->where('type', 'CustInvc')->find($invoiceId);
            if ($invoice === null) {
                throw new RuntimeException('Import this customer invoice before syncing its summary.');
            }
            $sync->handle($customerId, $invoiceId);
            $this->info('Invoice summary synced. Missing source amounts remain unavailable.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Invoice summary sync failed. Verify the invoice is current and accessible; existing summary was preserved.');

            return self::FAILURE;
        }
    }
}
