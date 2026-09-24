<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BackfillBalances extends Command
{
    protected $signature = 'milkstool:backfill-balances {--limit=3 : Maximum customers to fetch balance snapshots for} {--dry-run : List the next customers without importing}';

    protected $description = 'Import missing active customer balance snapshots sequentially, stopping at the first failure';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

        if ($limit === false) {
            $this->error('Limit must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $lock = Cache::lock('netsuite-balance-backfill', 86400);

        if (! $lock->get()) {
            $this->error('A balance backfill is already running.');

            return self::FAILURE;
        }

        try {
            $companies = Company::query()->where('is_active', true)->where(fn ($query) => $query->whereNull('balance_synced_at')->orWhereNull('account_balance_snapshot'))
                ->orderBy('id')->limit($limit)->get(['id']);
            $completed = 0;

            foreach ($companies as $company) {
                if (! $lock->refresh(86400) && ! $lock->isOwnedByCurrentProcess()) {
                    $this->error('The backfill lock expired. Rerun the command to resume.');

                    return self::FAILURE;
                }

                $this->line('Backfill customer '.$company->id);

                if ($this->option('dry-run')) {
                    continue;
                }

                if ($this->call('milkstool:sync-balance', ['customer' => $company->id]) !== self::SUCCESS) {
                    $this->error('Backfill stopped at customer '.$company->id.'. Completed customers will be skipped when you rerun.');

                    return self::FAILURE;
                }

                $completed++;
            }

            $this->info($this->option('dry-run') ? 'Preview complete. No balance snapshots imported.' : "Backfill complete: {$completed} customer balance snapshots imported.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
