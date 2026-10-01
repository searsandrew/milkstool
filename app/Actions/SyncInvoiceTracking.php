<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Transaction;
use App\Services\NetSuite\InvoiceTrackingSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncInvoiceTracking
{
    public function __construct(private InvoiceTrackingSource $source) {}

    /** @param list<int> $invoiceIds */
    public function handle(int $customerId, array $invoiceIds): int
    {
        if ($customerId < 1 || $invoiceIds === [] || count($invoiceIds) > 25 || count(array_unique($invoiceIds)) !== count($invoiceIds)) {
            throw new InvalidArgumentException('Provide a customer and 1 to 25 distinct invoice IDs.');
        }
        $lock = Cache::lock('netsuite-invoices:'.$customerId, 600);
        if (! $lock->get()) {
            throw new ReceivableSyncInterrupted('An invoice sync is already running for this customer.');
        }
        try {
            $invoices = Transaction::query()->where('company_id', $customerId)->where('type', 'CustInvc')->whereIn('id', $invoiceIds)->get();
            if ($invoices->count() !== count($invoiceIds)) {
                throw new InvalidArgumentException('Invoice has not been imported for this customer.');
            }
            $tracking = $this->source->fetch($customerId, $invoiceIds);
            if ($tracking !== $this->source->fetch($customerId, $invoiceIds)) {
                throw new ReceivableSyncInterrupted('Tracking changed during retrieval. Retry the command.');
            }
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('Invoice sync lock expired. Retry the command.');
            }
            DB::transaction(function () use ($invoices, $tracking): void {
                foreach ($invoices as $invoice) {
                    $details = $invoice->invoice_details ?? [];
                    if (($details['enrichment_error']['component'] ?? null) === 'tracking') {
                        unset($details['enrichment_error']);
                    }
                    $invoice->invoice_details = [...$details,
                        'tracking_numbers' => $tracking[$invoice->id],
                        'tracking_scope' => 'related_sales_orders',
                        'tracking_synced_at' => now()->utc()->toIso8601String(),
                        'tracking_header_updated_at' => $invoice->netsuite_updated_at?->utc()->format('Y-m-d H:i:s'),
                    ];
                    $invoice->save();
                }
            });

            return $invoices->count();
        } finally {
            $lock->release();
        }
    }
}
