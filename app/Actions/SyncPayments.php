<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Services\NetSuite\CustomerSource;
use App\Services\NetSuite\PaymentApplicationSource;
use App\Services\NetSuite\PaymentReconciliation;
use App\Services\NetSuite\PaymentSource;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Throwable;

class SyncPayments
{
    public function __construct(private PaymentSource $source, private PaymentApplicationSource $applications, private CustomerSource $customers, private StorePayment $store, private PaymentReconciliation $reconciliation) {}

    /**
     * @param  (Closure(int, int): void)|null  $onProgress
     * @return array{payments: int, lines: int, reconciliation: list<array{currency_id: int, metric: string, source: string, local: string, matches: bool}>}
     */
    public function handle(int $customerId, ?Closure $onProgress = null): array
    {
        $company = Company::query()->where('id', $customerId)->first();
        if ($company === null) {
            throw new RuntimeException('Customer is not registered. Run milkstool:sync-customers first.');
        }
        $lock = Cache::lock('netsuite-payments:'.$customerId, 600);
        if (! $lock->get()) {
            throw new ReceivableSyncInterrupted('A payment sync is already running for this customer.');
        }

        try {
            $company->forceFill(['payments_sync_started_at' => now(), 'payments_sync_error' => null])->save();
            $this->customers->find($customerId);
            $payments = 0;
            $lineCount = 0;
            foreach (LazyCollection::make(fn () => $this->source->payments($customerId))->chunk(50) as $batch) {
                $ids = $batch->map(fn (array $payment): int => (int) $payment['id'])->values()->all();
                $lines = $this->source->linesForPayments($customerId, $ids);
                $applications = $this->applications->forPayments($customerId, $ids);
                $latestApplications = $this->applications->forPayments($customerId, $ids);
                if ($applications != $latestApplications) {
                    throw new ReceivableSyncInterrupted('Payment applications changed during import. Retry the sync.');
                }
                $latest = $this->source->paymentsByIds($customerId, $ids);
                foreach ($batch as $payment) {
                    if ($payment != $latest[(int) $payment['id']]) {
                        throw new ReceivableSyncInterrupted('A payment changed during import. Retry the sync.');
                    }
                }
                foreach ($batch as $payment) {
                    if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                        throw new ReceivableSyncInterrupted('The payment sync lock expired. Retry the sync.');
                    }
                    $paymentLines = $lines[(int) $payment['id']];
                    $this->store->handle($company, $payment, $paymentLines, $applications[(int) $payment['id']]);
                    $payments++;
                    $lineCount += count($paymentLines);
                }
                $onProgress?->__invoke($payments, $lineCount);
            }
            if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The payment sync lock expired. Retry the sync.');
            }
            if ($company->transactions()->where('type', 'CustPymt')->count() !== $payments) {
                throw new RuntimeException('Local payment count differs from the source scan. Missing payments were retained; investigate before retrying.');
            }
            $results = $this->reconciliation->compare($company);
            $mismatch = collect($results)->firstWhere('matches', false);
            if ($mismatch !== null) {
                throw new RuntimeException('Payment reconciliation differs for currency '.$mismatch['currency_id'].', '.$mismatch['metric']
                    .': NetSuite '.$mismatch['source'].', local '.$mismatch['local'].'. Retry or investigate the mismatch.');
            }
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The payment sync lock expired. Retry the sync.');
            }
            $company->forceFill(['payments_synced_at' => now(), 'payments_sync_error' => null,
                'payments_next_sync_at' => $company->nextRefreshAt(),
                'payments_backfilled_at' => $company->payments_backfilled_at ?? now()])->save();

            return ['payments' => $payments, 'lines' => $lineCount, 'reconciliation' => $results];
        } catch (Throwable $exception) {
            if ($lock->isOwnedByCurrentProcess()) {
                $company->forceFill(['payments_sync_error' => 'Payment sync or reconciliation failed. Last successful sync is unchanged. Rerun milkstool:sync-payments for details.'])->save();
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
