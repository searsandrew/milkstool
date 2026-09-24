<?php

namespace App\Actions;

use App\Models\Company;
use App\Services\NetSuite\CustomerSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SyncCustomers
{
    public function __construct(private CustomerSource $source) {}

    /** @return array{created: int, updated: int, unchanged: int, ignored_inactive: int, skipped_busy: int, skipped_stale: int} */
    public function handle(bool $dryRun = false): array
    {
        $lock = Cache::lock('netsuite-customer-discovery', 600);

        if (! $lock->get()) {
            throw new RuntimeException('Customer discovery is already running.');
        }

        try {
            $customers = [];

            foreach ($this->source->all() as $customer) {
                if (count($customers) >= 10000) {
                    throw new RuntimeException('Customer discovery exceeded its 10,000-record safety limit. No customers were changed.');
                }

                $customers[] = $customer;

                if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('The customer discovery lock expired. Retry discovery.');
                }
            }

            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'ignored_inactive' => 0, 'skipped_busy' => 0, 'skipped_stale' => 0];

            foreach ($customers as $customer) {
                if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('The customer discovery lock expired. Retry discovery.');
                }

                $customerLock = Cache::lock('netsuite-sales-orders:'.$customer['id'], 600);

                if (! $customerLock->get()) {
                    $counts['skipped_busy']++;

                    continue;
                }

                try {
                    $counts[$this->store($customer, $dryRun)]++;
                } finally {
                    $customerLock->release();
                }
            }

        } finally {
            $lock->release();
        }

        return $counts;
    }

    /** @param array<string, mixed> $customer */
    private function store(array $customer, bool $dryRun): string
    {
        $company = Company::query()->firstOrNew(['id' => $customer['id']]);
        $active = $customer['isinactive'] === 'F';

        if (! $company->exists && ! $active) {
            return 'ignored_inactive';
        }

        if ($company->netsuite_updated_at?->greaterThan(CarbonImmutable::parse($customer['updated_at'], 'UTC'))) {
            return 'skipped_stale';
        }

        $reactivated = $company->exists && ! $company->is_active && $active;
        $company->fill([
            'name' => $customer['name'],
            'account_number' => $customer['account_number'] ?? null,
            'sales_rep_id' => $customer['sales_rep_id'] ?? null,
            'is_active' => $active,
            'netsuite_updated_at' => $customer['updated_at'],
            'raw_payload' => $company->exists && $company->raw_payload == $customer ? $company->raw_payload : $customer,
        ]);

        if ($reactivated) {
            $company->forceFill(['sales_orders_next_sync_at' => now()]);
        }

        $result = ! $company->exists ? 'created' : ($company->isDirty() ? 'updated' : 'unchanged');

        if (! $dryRun && $result !== 'unchanged') {
            $company->save();
        }

        return $result;
    }
}
