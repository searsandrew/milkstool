<?php

namespace App\Console\Commands;

use App\Jobs\RefreshCustomerBalance;
use App\Models\Company;
use Illuminate\Console\Command;

class DispatchBalanceRefreshes extends Command
{
    protected $signature = 'milkstool:dispatch-balance-refreshes {--dry-run : List due customers without queueing work}';

    protected $description = 'Queue due balance refreshes for active customers already registered in Milkstool';

    public function handle(): int
    {
        $count = 0;
        $companies = Company::query()->where('is_active', true)
            ->dueForRefresh('balance')
            ->select(['id', 'netsuite_id'])->lazyById(100);

        foreach ($companies as $company) {
            $count++;

            if ($this->option('dry-run')) {
                $this->line('Due: NetSuite customer '.$company->netsuite_id);
            } else {
                RefreshCustomerBalance::dispatch((int) $company->netsuite_id);
            }
        }

        $this->info($this->option('dry-run')
            ? "{$count} customers due. No jobs queued."
            : "Requested refreshes for {$count} due customers; already queued customers are deduplicated.");

        return self::SUCCESS;
    }
}
