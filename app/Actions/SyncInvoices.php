<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Services\NetSuite\CustomerSource;
use App\Services\NetSuite\InvoiceReconciliation;
use App\Services\NetSuite\InvoiceSource;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Throwable;

class SyncInvoices
{
    public function __construct(private InvoiceSource $source, private CustomerSource $customers, private StoreInvoice $store, private InvoiceReconciliation $reconciliation) {}

    /**
     * @param  (Closure(int, int): void)|null  $onProgress
     * @return array{invoices: int, lines: int, reconciliation: list<array{currency_id: int, metric: string, source: string, local: string, matches: bool}>}
     */
    public function handle(int $customerId, ?Closure $onProgress = null): array
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
            $company->forceFill(['invoices_sync_started_at' => now(), 'invoices_sync_error' => null])->save();
            $this->customers->find($customerId);
            $invoices = 0;
            $lineCount = 0;
            foreach (LazyCollection::make(fn () => $this->source->invoices($customerId))->chunk(50) as $batch) {
                $ids = $batch->map(fn (array $invoice): int => (int) $invoice['id'])->values()->all();
                $lines = $this->source->linesForInvoices($customerId, $ids);
                $latest = $this->source->invoicesByIds($customerId, $ids);
                foreach ($batch as $invoice) {
                    if ($invoice != $latest[(int) $invoice['id']]) {
                        throw new ReceivableSyncInterrupted('An invoice changed during import. Retry the sync.');
                    }
                }
                foreach ($batch as $invoice) {
                    if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                        throw new ReceivableSyncInterrupted('The invoice sync lock expired. Retry the sync.');
                    }
                    $invoiceLines = $lines[(int) $invoice['id']];
                    $this->store->handle($company, $invoice, $invoiceLines);
                    $invoices++;
                    $lineCount += count($invoiceLines);
                }
                $onProgress?->__invoke($invoices, $lineCount);
            }
            if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The invoice sync lock expired. Retry the sync.');
            }
            if ($company->transactions()->where('type', 'CustInvc')->count() !== $invoices) {
                throw new RuntimeException('Local invoice count differs from the source scan. Missing invoices were retained; investigate before retrying.');
            }
            $results = $this->reconciliation->compare($company);
            $mismatch = collect($results)->firstWhere('matches', false);
            if ($mismatch !== null) {
                throw new RuntimeException('Invoice reconciliation differs for currency '.$mismatch['currency_id'].', '.$mismatch['metric']
                    .': NetSuite '.$mismatch['source'].', local '.$mismatch['local'].'. Retry or investigate the mismatch.');
            }
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The invoice sync lock expired. Retry the sync.');
            }
            $company->forceFill(['invoices_synced_at' => now(), 'invoices_sync_error' => null,
                'invoices_next_sync_at' => $company->nextRefreshAt(),
                'invoices_backfilled_at' => $company->invoices_backfilled_at ?? now()])->save();

            return ['invoices' => $invoices, 'lines' => $lineCount, 'reconciliation' => $results];
        } catch (Throwable $exception) {
            if ($lock->isOwnedByCurrentProcess()) {
                $company->forceFill(['invoices_sync_error' => 'Invoice sync or reconciliation failed. Last successful sync is unchanged. Rerun milkstool:sync-invoices for details.'])->save();
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
