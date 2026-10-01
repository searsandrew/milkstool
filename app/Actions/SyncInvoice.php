<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Models\Transaction;
use App\Services\NetSuite\InvoiceSource;
use App\Services\NetSuite\InvoiceSummarySource;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SyncInvoice
{
    public function __construct(private InvoiceSource $source, private StoreInvoice $store, private InvoiceSummarySource $summaries) {}

    public function handle(int $customerId, int $invoiceId): Transaction
    {
        $company = Company::query()->where('id', $customerId)->first();

        if ($company === null) {
            throw new RuntimeException('Customer is not registered. Run milkstool:sync-customers first.');
        }

        $lock = Cache::lock('netsuite-invoices:'.$customerId, 600);

        if (! $lock->get()) {
            throw new ReceivableSyncInterrupted('An invoice sync is already running for this customer.');
        }

        try {
            $invoice = $this->source->invoice($customerId, $invoiceId);
            $lines = $this->source->linesForInvoices($customerId, [$invoiceId])[$invoiceId];
            $summary = $this->summaries->forInvoice($customerId, $invoice, $lines);
            $latest = $this->source->invoice($customerId, $invoiceId);

            if ($invoice != $latest) {
                throw new ReceivableSyncInterrupted('The invoice changed during import. Retry the sync.');
            }

            if (! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The invoice sync lock expired. Retry the sync.');
            }

            return $this->store->handle($company, $invoice, $lines, $summary);
        } finally {
            $lock->release();
        }
    }
}
