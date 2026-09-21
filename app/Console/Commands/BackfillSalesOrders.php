<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BackfillSalesOrders extends Command
{
    protected $signature = 'milkstool:backfill-sales-orders {--limit=3 : Maximum customers to import and reconcile} {--dry-run : List the next customers without importing}';

    protected $description = 'Backfill active customers sequentially, stopping at the first import or reconciliation failure';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

        if ($limit === false) {
            $this->error('Limit must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $lock = Cache::lock('netsuite-sales-order-backfill', 86400);

        if (! $lock->get()) {
            $this->error('A sales-order backfill is already running.');

            return self::FAILURE;
        }

        try {
            $companies = Company::query()->where('is_active', true)->whereNull('sales_orders_backfilled_at')
                ->orderBy('netsuite_id')->limit($limit)->get(['id', 'netsuite_id']);
            $completed = 0;

            foreach ($companies as $company) {
                if (! $lock->refresh(86400) && ! $lock->isOwnedByCurrentProcess()) {
                    $this->error('The backfill lock expired. Rerun the command to resume.');

                    return self::FAILURE;
                }

                $this->line('Backfill customer '.$company->netsuite_id);

                if ($this->option('dry-run')) {
                    continue;
                }

                if ($this->call('milkstool:sync-sales-orders', ['customer' => $company->netsuite_id, '--incremental' => true]) !== self::SUCCESS
                    || $this->call('milkstool:reconcile-sales-orders', ['customer' => $company->netsuite_id]) !== self::SUCCESS) {
                    $this->error('Backfill stopped at customer '.$company->netsuite_id.'. Completed customers will be skipped when you rerun.');

                    return self::FAILURE;
                }

                $company->forceFill(['sales_orders_backfilled_at' => now()])->save();
                $completed++;
            }

            $this->info($this->option('dry-run') ? 'Preview complete. No transactions imported.' : "Backfill complete: {$completed} customers imported and reconciled.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
