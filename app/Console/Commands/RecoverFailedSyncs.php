<?php

namespace App\Console\Commands;

use App\Jobs\RefreshCreditMemos;
use App\Jobs\RefreshCustomerBalance;
use App\Jobs\RefreshInvoices;
use App\Jobs\RefreshPayments;
use App\Jobs\RefreshSalesOrders;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RecoverFailedSyncs extends Command
{
    protected $signature = 'milkstool:recover-failed-syncs
        {--type=invoices : invoices, sales-orders, credit-memos, payments, or balances}
        {--customer= : Recover only this active customer}
        {--limit=5 : Maximum customers, between 1 and 1000}
        {--dry-run : List candidates without queueing}';

    protected $description = 'Queue fresh, deduplicated refreshes for failed customers while preserving failure evidence';

    public function handle(): int
    {
        $types = [
            'invoices' => ['invoices', RefreshInvoices::class],
            'sales-orders' => ['sales_orders', RefreshSalesOrders::class],
            'credit-memos' => ['credit_memos', RefreshCreditMemos::class],
            'payments' => ['payments', RefreshPayments::class],
            'balances' => ['balance', RefreshCustomerBalance::class],
        ];
        $type = $this->option('type');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $customer = $this->option('customer') === null ? null : filter_var($this->option('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! isset($types[$type]) || $limit === false || $customer === false) {
            $this->error('Provide a supported type, a limit between 1 and 1000, and a positive customer ID.');

            return self::FAILURE;
        }
        [$prefix, $job] = $types[$type];
        $companies = Company::query()->where('is_active', true)->whereNotNull($prefix.'_sync_error')
            ->when($customer !== null, fn (Builder $query) => $query->whereKey($customer))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($companies as $index => $id) {
            $this->line(($this->option('dry-run') ? 'Candidate' : 'Requested').': '.$type.' for customer '.$id);
            if (! $this->option('dry-run')) {
                $job::dispatch((int) $id)->delay(now()->addSeconds($index * 30));
            }
        }
        $this->info($companies->count().' candidates. '.($this->option('dry-run')
            ? 'No jobs queued.' : 'Refresh requests staggered by 30 seconds; already queued customers are deduplicated.'));
        $this->line('Failed-job records and customer error state are retained; successful refreshes clear customer errors.');

        return self::SUCCESS;
    }
}
