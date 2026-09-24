<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BackfillInvoices extends Command
{
    protected $signature = 'milkstool:backfill-invoices {--limit=3 : Maximum customers to import and reconcile} {--dry-run : List the next customers without importing}';

    protected $description = 'Backfill active customers sequentially, stopping at the first import or reconciliation failure';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

        if ($limit === false) {
            $this->error('Limit must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $lock = Cache::lock('netsuite-invoice-backfill', 86400);

        if (! $lock->get()) {
            $this->error('A invoice backfill is already running.');

            return self::FAILURE;
        }

        try {
            $companies = Company::query()->where('is_active', true)->whereNull('invoices_backfilled_at')
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

                if ($this->call('milkstool:sync-invoices', ['customer' => $company->id]) !== self::SUCCESS) {
                    $this->error('Backfill stopped at customer '.$company->id.'. Completed customers will be skipped when you rerun.');

                    return self::FAILURE;
                }

                $completed++;
            }

            $this->info($this->option('dry-run') ? 'Preview complete. No transactions imported.' : "Backfill complete: {$completed} customers imported and reconciled.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
