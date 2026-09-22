<?php

namespace App\Console\Commands;

use App\Jobs\RefreshSalesOrders;
use App\Models\Company;
use Illuminate\Console\Command;

class DispatchSalesOrderRefreshes extends Command
{
    protected $signature = 'milkstool:dispatch-sales-order-refreshes {--dry-run : List due customers without queueing work}';

    protected $description = 'Queue due sales-order refreshes for active customers already registered in Milkstool';

    public function handle(): int
    {
        $count = 0;
        $companies = Company::query()->where('is_active', true)
            ->dueForRefresh('sales_orders')
            ->select(['id', 'netsuite_id'])->lazyById(100);

        foreach ($companies as $company) {
            $count++;

            if ($this->option('dry-run')) {
                $this->line('Due: NetSuite customer '.$company->netsuite_id);
            } else {
                RefreshSalesOrders::dispatch((int) $company->netsuite_id);
            }
        }

        $this->info($this->option('dry-run')
            ? "{$count} customers due. No jobs queued."
            : "Requested refreshes for {$count} due customers; already queued customers are deduplicated.");

        return self::SUCCESS;
    }
}
