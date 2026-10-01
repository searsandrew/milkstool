<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Models\Transaction;
use App\Services\NetSuite\InvoiceSource;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SyncInvoice
{
    public function __construct(private InvoiceSource $source, private ImportInvoiceBatch $import) {}

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
            $this->import->handle($company, [$invoice], function () use ($lock): void {
                if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                    throw new ReceivableSyncInterrupted('Invoice sync lock expired. Retry the command.');
                }
            }, function (int $lines): void {});

            return $company->transactions()->where('id', $invoiceId)->firstOrFail();
        } finally {
            $lock->release();
        }
    }
}
