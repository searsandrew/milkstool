<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\NetSuite\InvoiceSource;
use App\Services\NetSuite\InvoiceSummarySource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SyncInvoiceSummary extends Command
{
    protected $signature = 'milkstool:sync-invoice-summary {customer} {invoice}';

    protected $description = 'Copy one invoice summary directly from NetSuite without calculating totals';

    public function handle(InvoiceSummarySource $source, InvoiceSource $headers): int
    {
        $customerId = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $invoiceId = filter_var($this->argument('invoice'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($customerId === false || $invoiceId === false) {
            $this->error('Customer and invoice IDs must be positive integers.');

            return self::FAILURE;
        }
        $lock = Cache::lock('netsuite-invoices:'.$customerId, 600);
        if (! $lock->get()) {
            $this->error('An invoice sync is already running for this customer.');

            return self::FAILURE;
        }
        try {
            $invoice = Transaction::query()->where('company_id', $customerId)->where('type', 'CustInvc')->find($invoiceId);
            if ($invoice === null) {
                throw new RuntimeException('Import this customer invoice before syncing its summary.');
            }
            $summary = $source->fetch($customerId, $invoiceId);
            if ($summary !== $source->fetch($customerId, $invoiceId)) {
                throw new RuntimeException('Invoice summary changed during retrieval. Retry.');
            }
            $header = $headers->invoice($customerId, $invoiceId);
            $modified = CarbonImmutable::parse($header['updated_at'], 'UTC');
            $recordModified = CarbonImmutable::parse($summary['source_modified_at'])->utc();
            if (! $recordModified->startOfMinute()->equalTo($modified->startOfMinute())
                || $invoice->netsuite_updated_at === null || ! $modified->equalTo($invoice->netsuite_updated_at)
                || $summary['total'] !== $invoice->foreign_total || $summary['currency_id'] !== (int) $invoice->currency_id) {
                throw new RuntimeException('Invoice header is out of date. Sync the invoice before its summary.');
            }
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new RuntimeException('Invoice sync lock expired. Retry.');
            }
            $summary['source_modified_at'] = $modified->toIso8601String();
            $summary['synced_at'] = now()->utc()->toIso8601String();
            $invoice->invoice_details = [...($invoice->invoice_details ?? []), 'summary' => $summary];
            $invoice->save();
            $this->info('Invoice summary synced. Missing source amounts remain unavailable.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Invoice summary sync failed. Verify the invoice is current and accessible; existing summary was preserved.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
