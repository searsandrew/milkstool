<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Services\NetSuite\InvoiceSource;
use App\Services\NetSuite\InvoiceSummarySource;
use App\Services\NetSuite\InvoiceTrackingSource;
use Closure;
use Illuminate\Support\Facades\DB;

class ImportInvoiceBatch
{
    public function __construct(private InvoiceSource $source, private InvoiceSummarySource $summaries,
        private InvoiceTrackingSource $tracking, private StoreInvoice $store, private StoreInvoiceTracking $storeTracking) {}

    /**
     * Import a complete batch while the caller holds the customer invoice lock.
     *
     * @param  list<array<string, mixed>>  $headers
     * @param  Closure(): void  $refreshLock
     * @param  Closure(int): void  $saveProgress
     */
    public function handle(Company $company, array $headers, Closure $refreshLock, Closure $saveProgress): void
    {
        $ids = array_map(fn (array $header): int => (int) $header['id'], $headers);
        $lines = $this->source->linesForInvoices((int) $company->id, $ids);
        $existing = $company->transactions()->whereIn('id', $ids)->with(['lines' => fn ($query) => $query->orderBy('netsuite_line_id')])->get()->keyBy('id');
        $summaries = [];
        $refresh = [];
        foreach ($headers as $header) {
            $id = (int) $header['id'];
            $stored = $existing->get($id);
            $unchanged = $stored !== null && $stored->raw_payload == $header
                && $stored->lines->pluck('raw_payload')->all() == $lines[$id];
            if (! $unchanged || ! $stored->hasCurrentInvoiceEnrichment() || ! $stored->hasCurrentInvoiceTracking()) {
                $refreshLock();
                $summaries[$id] = $this->summaries->forInvoice((int) $company->id, $header, $lines[$id]);
                $refresh[] = $id;
            }
        }
        $tracking = [];
        foreach (array_chunk($refresh, 25) as $chunk) {
            $refreshLock();
            $snapshot = $this->tracking->fetch((int) $company->id, $chunk);
            if ($snapshot !== $this->tracking->fetch((int) $company->id, $chunk)) {
                throw new ReceivableSyncInterrupted('Tracking changed during invoice import. Retry the batch.');
            }
            $tracking += $snapshot;
        }
        $latest = $this->source->invoicesByIds((int) $company->id, $ids);
        foreach ($headers as $header) {
            if ($header != $latest[(int) $header['id']]) {
                throw new ReceivableSyncInterrupted('An invoice changed during import. Retry the batch.');
            }
        }
        $refreshLock();
        DB::transaction(function () use ($company, $headers, $lines, $summaries, $tracking, $saveProgress): void {
            foreach ($headers as $header) {
                $id = (int) $header['id'];
                if (isset($summaries[$id])) {
                    $invoice = $this->store->handle($company, $header, $lines[$id], $summaries[$id]);
                    $this->storeTracking->handle($invoice, $tracking[$id]);
                }
            }
            $saveProgress(array_sum(array_map(count(...), $lines)));
        });
    }
}
