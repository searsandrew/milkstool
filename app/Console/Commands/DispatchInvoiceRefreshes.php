<?php

namespace App\Console\Commands;

use App\Jobs\RefreshInvoices;
use App\Models\Company;
use Illuminate\Console\Command;

class DispatchInvoiceRefreshes extends Command
{
    protected $signature = 'milkstool:dispatch-invoice-refreshes {--dry-run : List due customers without queueing work}';

    protected $description = 'Queue due invoice refreshes for active customers already registered in Milkstool';

    public function handle(): int
    {
        $count = 0;
        $companies = Company::query()->where('is_active', true)
            ->dueForRefresh('invoices')
            ->select(['id'])->lazyById(100);

        foreach ($companies as $company) {
            $count++;

            if ($this->option('dry-run')) {
                $this->line('Due: NetSuite customer '.$company->id);
            } else {
                RefreshInvoices::dispatch((int) $company->id);
            }
        }

        $this->info($this->option('dry-run')
            ? "{$count} customers due. No jobs queued."
            : "Requested refreshes for {$count} due customers; already queued customers are deduplicated.");

        return self::SUCCESS;
    }
}
