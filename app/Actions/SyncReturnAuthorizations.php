<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Services\NetSuite\CustomerSource;
use App\Services\NetSuite\ReturnAuthorizationSource;
use Closure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SyncReturnAuthorizations
{
    public function __construct(private ReturnAuthorizationSource $source, private StoreReturnAuthorization $store, private CustomerSource $customers) {}

    /** @param (Closure(int, int): void)|null $onProgress
     * @return array{returnAuthorizations: int, lines: int}
     */
    public function handle(int $customerId, ?Closure $onProgress = null): array
    {
        $company = Company::query()->find($customerId);
        if ($company === null) {
            throw new RuntimeException('Customer is not registered. Run milkstool:sync-customers first.');
        }
        $lock = Cache::lock('netsuite-return-authorizations:'.$customerId, 600);
        if (! $lock->get()) {
            throw new ReceivableSyncInterrupted('An RMA sync is already running for this customer.');
        }
        try {
            $company->forceFill(['return_authorizations_sync_started_at' => now(), 'return_authorizations_sync_error' => null])->save();
            $this->customers->find($customerId);
            $seen = [];
            $count = 0;
            $lineCount = 0;
            foreach ($this->source->headers($customerId) as $header) {
                $lines = $this->source->lines($customerId, $header);
                if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                    throw new ReceivableSyncInterrupted('The RMA sync lock expired. Retry the sync.');
                }
                $this->store->handle($company, $header, $lines);
                $seen[(int) $header['id']] = hash('sha256', json_encode($header, JSON_THROW_ON_ERROR));
                $count++;
                $lineCount += count($lines);
                $onProgress?->__invoke($count, $lineCount);
            }
            foreach ($this->source->headers($customerId) as $header) {
                $id = (int) $header['id'];
                if (($seen[$id] ?? null) !== hash('sha256', json_encode($header, JSON_THROW_ON_ERROR))) {
                    throw new ReceivableSyncInterrupted('The RMA listing changed during import. Retry the sync.');
                }
                unset($seen[$id]);
            }
            if ($seen !== [] || $company->transactions()->where('type', 'RtnAuth')->count() !== $count) {
                throw new ReceivableSyncInterrupted('An RMA disappeared during import. Missing local records were retained.');
            }
            if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The RMA sync lock expired. Retry the sync.');
            }
            $company->forceFill(['return_authorizations_synced_at' => now(), 'return_authorizations_sync_error' => null,
                'return_authorizations_next_sync_at' => $company->nextRefreshAt(),
                'return_authorizations_backfilled_at' => $company->return_authorizations_backfilled_at ?? now()])->save();

            return ['returnAuthorizations' => $count, 'lines' => $lineCount];
        } catch (Throwable $exception) {
            if ($lock->isOwnedByCurrentProcess()) {
                $company->forceFill(['return_authorizations_sync_error' => 'RMA sync failed. Last successful sync is unchanged. Rerun milkstool:sync-return-authorizations for details.'])->save();
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
