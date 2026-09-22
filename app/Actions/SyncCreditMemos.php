<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Services\NetSuite\CreditMemoApplicationSource;
use App\Services\NetSuite\CreditMemoReconciliation;
use App\Services\NetSuite\CreditMemoSource;
use App\Services\NetSuite\CustomerSource;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Throwable;

class SyncCreditMemos
{
    public function __construct(private CreditMemoSource $source, private CreditMemoApplicationSource $applications, private CustomerSource $customers, private StoreCreditMemo $store, private CreditMemoReconciliation $reconciliation) {}

    /**
     * @param  (Closure(int, int): void)|null  $onProgress
     * @return array{creditMemos: int, lines: int, reconciliation: list<array{currency_id: int, metric: string, source: string, local: string, matches: bool}>}
     */
    public function handle(int $customerId, ?Closure $onProgress = null): array
    {
        $company = Company::query()->where('netsuite_id', $customerId)->first();
        if ($company === null) {
            throw new RuntimeException('Customer is not registered. Run milkstool:sync-customers first.');
        }
        $lock = Cache::lock('netsuite-credit-memos:'.$customerId, 600);
        if (! $lock->get()) {
            throw new ReceivableSyncInterrupted('A credit memo sync is already running for this customer.');
        }

        try {
            $company->forceFill(['credit_memos_sync_started_at' => now(), 'credit_memos_sync_error' => null])->save();
            $this->customers->find($customerId);
            $creditMemos = 0;
            $lineCount = 0;
            foreach (LazyCollection::make(fn () => $this->source->creditMemos($customerId))->chunk(50) as $batch) {
                $ids = $batch->map(fn (array $creditMemo): int => (int) $creditMemo['id'])->values()->all();
                $lines = $this->source->linesForCreditMemos($customerId, $ids);
                $applications = $this->applications->forCreditMemos($customerId, $ids);
                $latestApplications = $this->applications->forCreditMemos($customerId, $ids);
                if ($applications != $latestApplications) {
                    throw new ReceivableSyncInterrupted('Credit memo applications changed during import. Retry the sync.');
                }
                $latest = $this->source->creditMemosByIds($customerId, $ids);
                foreach ($batch as $creditMemo) {
                    if ($creditMemo != $latest[(int) $creditMemo['id']]) {
                        throw new ReceivableSyncInterrupted('A credit memo changed during import. Retry the sync.');
                    }
                }
                foreach ($batch as $creditMemo) {
                    if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                        throw new ReceivableSyncInterrupted('The credit memo sync lock expired. Retry the sync.');
                    }
                    $creditMemoLines = $lines[(int) $creditMemo['id']];
                    $this->store->handle($company, $creditMemo, $creditMemoLines, $applications[(int) $creditMemo['id']]);
                    $creditMemos++;
                    $lineCount += count($creditMemoLines);
                }
                $onProgress?->__invoke($creditMemos, $lineCount);
            }
            if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The credit memo sync lock expired. Retry the sync.');
            }
            if ($company->transactions()->where('type', 'CustCred')->count() !== $creditMemos) {
                throw new RuntimeException('Local credit memo count differs from the source scan. Missing credit memos were retained; investigate before retrying.');
            }
            $results = $this->reconciliation->compare($company);
            $mismatch = collect($results)->firstWhere('matches', false);
            if ($mismatch !== null) {
                throw new RuntimeException('Credit memo reconciliation differs for currency '.$mismatch['currency_id'].', '.$mismatch['metric']
                    .': NetSuite '.$mismatch['source'].', local '.$mismatch['local'].'. Retry or investigate the mismatch.');
            }
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The credit memo sync lock expired. Retry the sync.');
            }
            $company->forceFill(['credit_memos_synced_at' => now(), 'credit_memos_sync_error' => null,
                'credit_memos_next_sync_at' => $company->nextRefreshAt(),
                'credit_memos_backfilled_at' => $company->credit_memos_backfilled_at ?? now()])->save();

            return ['creditMemos' => $creditMemos, 'lines' => $lineCount, 'reconciliation' => $results];
        } catch (Throwable $exception) {
            if ($lock->isOwnedByCurrentProcess()) {
                $company->forceFill(['credit_memos_sync_error' => 'Credit memo sync or reconciliation failed. Last successful sync is unchanged. Rerun milkstool:sync-credit-memos for details.'])->save();
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
