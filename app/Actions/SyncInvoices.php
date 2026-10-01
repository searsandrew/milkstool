<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Services\NetSuite\CustomerSource;
use App\Services\NetSuite\InvoiceReconciliation;
use App\Services\NetSuite\InvoiceSource;
use App\Services\NetSuite\InvoiceTrackingSource;
use App\Services\NetSuite\SourceClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SyncInvoices
{
    public function __construct(private InvoiceSource $source, private CustomerSource $customers,
        private ImportInvoiceBatch $import, private InvoiceReconciliation $reconciliation,
        private SourceClock $clock, private InvoiceTrackingSource $tracking, private StoreInvoiceTracking $storeTracking) {}

    /**
     * @param  (Closure(int, int): void)|null  $onProgress
     * @return array{invoices: int, lines: int, complete: bool, reconciliation: list<array{currency_id: int, metric: string, source: string, local: string, matches: bool}>}
     */
    public function handle(int $customerId, ?Closure $onProgress = null, bool $incremental = false, ?int $maxBatches = null): array
    {
        if ($maxBatches !== null && $maxBatches < 1) {
            throw new InvalidArgumentException('Batch limit must be positive.');
        }
        $company = Company::query()->find($customerId);
        if ($company === null) {
            throw new RuntimeException('Customer is not registered. Run milkstool:sync-customers first.');
        }
        $lock = Cache::lock('netsuite-invoices:'.$customerId, 600);
        if (! $lock->get()) {
            throw new ReceivableSyncInterrupted('An invoice sync is already running for this customer.');
        }
        $refreshLock = function () use ($lock): void {
            if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The invoice sync lock expired. Retry the sync.');
            }
        };
        try {
            $company->refresh();
            $company->forceFill(['invoices_sync_started_at' => now(), 'invoices_sync_error' => null])->save();
            $this->customers->find($customerId);
            $state = $company->invoices_import_state;
            if ($state === null) {
                $checkpoint = $this->clock->currentTime()->subMinutes(2);
                if ($company->invoices_checkpoint_at?->greaterThan($checkpoint)) {
                    throw new ReceivableSyncInterrupted('NetSuite source clock moved backwards. Retry the sync.');
                }
                $full = ! $incremental || $company->invoices_checkpoint_at === null
                    || $company->invoices_backfilled_at === null || $company->transactions()->needsInvoiceWork()->exists();
                $state = ['phase' => 'invoices', 'full' => $full, 'cursor' => 0, 'tracking_cursor' => 0,
                    'invoices' => 0, 'lines' => 0, 'started_at' => now()->utc()->toIso8601String(),
                    'checkpoint' => $checkpoint->toIso8601String(),
                    'since' => $full ? null : $company->invoices_checkpoint_at->subMinutes(5)->toIso8601String()];
                $company->forceFill(['invoices_import_state' => $state])->save();
            }
            $batches = 0;
            while (true) {
                $until = CarbonImmutable::parse($state['checkpoint']);
                $since = $state['since'] === null ? null : CarbonImmutable::parse($state['since']);
                if ($state['phase'] === 'invoices') {
                    foreach (LazyCollection::make(fn () => $this->source->invoices($customerId, $since, $since === null ? null : $until, $state['cursor']))->chunk(50) as $batch) {
                        $headers = $batch->values()->all();
                        $this->import->handle($company, $headers, $refreshLock, function (int $lines) use ($company, $headers, &$state): void {
                            $state['cursor'] = (int) $headers[array_key_last($headers)]['id'];
                            $state['invoices'] += count($headers);
                            $state['lines'] += $lines;
                            $company->forceFill(['invoices_import_state' => $state])->save();
                        });
                        $onProgress?->__invoke($state['invoices'], $state['lines']);
                        if (++$batches >= ($maxBatches ?? PHP_INT_MAX) && count($headers) === 50) {
                            return ['invoices' => $state['invoices'], 'lines' => $state['lines'], 'complete' => false, 'reconciliation' => []];
                        }
                    }
                    $state['phase'] = 'tracking';
                    $company->forceFill(['invoices_import_state' => $state])->save();
                }
                if ($state['phase'] === 'tracking' && $since !== null) {
                    foreach (LazyCollection::make(fn () => $this->tracking->changedInvoices($customerId, $since, $until, $state['tracking_cursor']))->chunk(25) as $batch) {
                        $refreshLock();
                        $invoices = $company->transactions()->where('type', 'CustInvc')->whereIn('id', $batch->all())
                            ->where(fn ($query) => $query->whereNull('invoice_details->tracking_synced_at')
                                ->orWhere('invoice_details->tracking_synced_at', '<', $state['started_at']))->get();
                        $numbers = [];
                        if ($invoices->isNotEmpty()) {
                            $numbers = $this->tracking->fetch($customerId, $invoices->modelKeys());
                            if ($numbers !== $this->tracking->fetch($customerId, $invoices->modelKeys())) {
                                throw new ReceivableSyncInterrupted('Tracking changed during retrieval. Retry the batch.');
                            }
                        }
                        $refreshLock();
                        DB::transaction(function () use ($company, $invoices, $numbers, $batch, &$state): void {
                            foreach ($invoices as $invoice) {
                                $this->storeTracking->handle($invoice, $numbers[$invoice->id]);
                            }
                            $state['tracking_cursor'] = (int) $batch->last();
                            $company->forceFill(['invoices_import_state' => $state])->save();
                        });
                        if (++$batches >= ($maxBatches ?? PHP_INT_MAX) && $batch->count() === 25) {
                            return ['invoices' => $state['invoices'], 'lines' => $state['lines'], 'complete' => false, 'reconciliation' => []];
                        }
                    }
                }
                $refreshLock();
                if ($state['full'] && $company->transactions()->where('type', 'CustInvc')->count() !== $state['invoices']) {
                    $company->forceFill(['invoices_import_state' => null])->save();
                    throw new RuntimeException('Local invoice count differs from the source scan. Missing invoices were retained; investigate before retrying.');
                }
                $results = $this->reconciliation->compare($company);
                $mismatch = collect($results)->firstWhere('matches', false);
                if ($mismatch !== null) {
                    $wasFull = $state['full'];
                    $state = [...$state, 'phase' => 'invoices', 'full' => true, 'since' => null, 'cursor' => 0, 'tracking_cursor' => 0, 'invoices' => 0, 'lines' => 0];
                    $company->forceFill(['invoices_import_state' => $state])->save();
                    if (! $wasFull) {
                        if ($maxBatches !== null && $batches >= $maxBatches) {
                            return ['invoices' => 0, 'lines' => 0, 'complete' => false, 'reconciliation' => $results];
                        }

                        continue;
                    }
                    throw new RuntimeException('Invoice reconciliation differs for currency '.$mismatch['currency_id'].', '.$mismatch['metric']
                        .': NetSuite '.$mismatch['source'].', local '.$mismatch['local'].'. Retry or investigate the mismatch.');
                }
                $refreshLock();
                if ($company->transactions()->needsInvoiceWork()->exists()) {
                    throw new ReceivableSyncInterrupted('Invoice details are incomplete. The customer cannot be marked synchronized.');
                }
                $company->forceFill(['invoices_checkpoint_at' => $until,
                    'invoices_full_synced_at' => $state['full'] ? now() : $company->invoices_full_synced_at,
                    'invoices_synced_at' => now(), 'invoices_sync_error' => null,
                    'invoices_next_sync_at' => $company->nextRefreshAt(), 'invoices_import_state' => null,
                    'invoices_backfilled_at' => $company->invoices_backfilled_at ?? now()])->save();

                return ['invoices' => $state['invoices'], 'lines' => $state['lines'], 'complete' => true, 'reconciliation' => $results];
            }
        } catch (Throwable $exception) {
            if ($lock->isOwnedByCurrentProcess()) {
                $company->forceFill(['invoices_sync_error' => 'Invoice import did not complete. Validated batches were retained; the next attempt resumes automatically.'])->save();
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
