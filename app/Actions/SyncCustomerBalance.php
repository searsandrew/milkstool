<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Services\NetSuite\CustomerBalanceSource;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SyncCustomerBalance
{
    public function __construct(private CustomerBalanceSource $source) {}

    /** @return array<string, mixed> */
    public function handle(int $customerId): array
    {
        $company = Company::query()->where('id', $customerId)->first();
        if ($company === null) {
            throw new RuntimeException('Customer is not registered. Run milkstool:sync-customers first.');
        }
        $lock = Cache::lock('netsuite-balance:'.$customerId, 600);
        if (! $lock->get()) {
            throw new ReceivableSyncInterrupted('A balance refresh is already running for this customer.');
        }
        try {
            $company->forceFill(['balance_sync_started_at' => now(), 'balance_sync_error' => null])->save();
            $snapshot = $this->source->find($customerId);
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The balance sync lock expired. Retry the sync.');
            }
            $company->forceFill(['account_balance_snapshot' => $snapshot, 'balance_synced_at' => now(),
                'balance_sync_error' => null, 'balance_next_sync_at' => $company->nextRefreshAt()])->save();

            return $snapshot;
        } catch (Throwable $exception) {
            if ($lock->isOwnedByCurrentProcess()) {
                $company->forceFill(['balance_sync_error' => 'Balance refresh failed. The last successful snapshot was retained.'])->save();
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
