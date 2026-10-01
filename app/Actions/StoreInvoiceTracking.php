<?php

namespace App\Actions;

use App\Models\Transaction;

class StoreInvoiceTracking
{
    /** Persist validated tracking while the caller holds the customer invoice lock.
     * @param  list<string>  $numbers
     */
    public function handle(Transaction $invoice, array $numbers): void
    {
        $details = $invoice->invoice_details ?? [];
        if (($details['enrichment_error']['component'] ?? null) === 'tracking') {
            unset($details['enrichment_error']);
        }
        $invoice->invoice_details = [...$details,
            'tracking_dirty' => false,
            'tracking_numbers' => $numbers,
            'tracking_scope' => 'related_sales_orders',
            'tracking_synced_at' => now()->utc()->toIso8601String(),
            'tracking_header_updated_at' => $invoice->netsuite_updated_at?->utc()->format('Y-m-d H:i:s'),
        ];
        $invoice->save();
    }
}
