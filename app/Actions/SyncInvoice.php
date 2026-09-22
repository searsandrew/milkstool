<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Transaction;
use App\Services\NetSuite\InvoiceSource;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SyncInvoice
{
    public function __construct(private InvoiceSource $source, private StoreInvoice $store) {}

    public function handle(int $customerId, int $invoiceId): Transaction
    {
        $company = Company::query()->where('netsuite_id', $customerId)->first();

        if ($company === null) {
            throw new RuntimeException('Customer is not registered. Run milkstool:sync-customers first.');
        }

        $lock = Cache::lock('netsuite-invoices:'.$customerId, 600);

        if (! $lock->get()) {
            throw new RuntimeException('An invoice sync is already running for this customer.');
        }

        try {
            $invoice = $this->source->invoice($customerId, $invoiceId);
            $lines = $this->source->linesForInvoices($customerId, [$invoiceId])[$invoiceId];
            $latest = $this->source->invoice($customerId, $invoiceId);

            if ($invoice != $latest) {
                throw new RuntimeException('The invoice changed during import. Retry the sync.');
            }

            if (! $lock->isOwnedByCurrentProcess()) {
                throw new RuntimeException('The invoice sync lock expired. Retry the sync.');
            }

            return $this->store->handle($company, $invoice, $lines);
        } finally {
            $lock->release();
        }
    }
}
