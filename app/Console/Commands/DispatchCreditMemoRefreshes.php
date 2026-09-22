<?php

namespace App\Console\Commands;

use App\Jobs\RefreshCreditMemos;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DispatchCreditMemoRefreshes extends Command
{
    protected $signature = 'milkstool:dispatch-credit-memo-refreshes {--dry-run : List due customers without queueing work}';

    protected $description = 'Queue due credit memo refreshes for active customers already registered in Milkstool';

    public function handle(): int
    {
        $dueAt = now();
        $count = 0;
        $companies = Company::query()->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('credit_memos_next_sync_at')->orWhere('credit_memos_next_sync_at', '<=', $dueAt))
            ->select(['id', 'netsuite_id'])->lazyById(100);

        foreach ($companies as $company) {
            $count++;

            if ($this->option('dry-run')) {
                $this->line('Due: NetSuite customer '.$company->netsuite_id);
            } else {
                RefreshCreditMemos::dispatch((int) $company->netsuite_id);
            }
        }

        $this->info($this->option('dry-run')
            ? "{$count} customers due. No jobs queued."
            : "Requested refreshes for {$count} due customers; already queued customers are deduplicated.");

        return self::SUCCESS;
    }
}
