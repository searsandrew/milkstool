<?php

namespace App\Console\Commands;

use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\OutputInterface;

class SyncStatus extends Command
{
    protected $signature = 'milkstool:sync-status
        {--type=sales-orders : sales-orders, invoices, credit-memos, payments, or balances}
        {--customer= : NetSuite internal ID, including inactive customers}
        {--attention : Show only active customers with unfinished backfills or refreshes needing attention}
        {--include-inactive : Include inactive customers in the list}
        {--json : Output a machine-readable report}';

    protected $description = 'Show local transaction sync freshness, backfill completion, and queue counts without contacting NetSuite';

    public function handle(): int
    {
        $types = [
            'sales-orders' => ['sales_orders', 'SalesOrd', 'sales_order_count', 'Orders'],
            'invoices' => ['invoices', 'CustInvc', 'invoice_count', 'Invoices'],
            'credit-memos' => ['credit_memos', 'CustCred', 'credit_memo_count', 'Credit memos'],
            'payments' => ['payments', 'CustPymt', 'payment_count', 'Payments'],
            'balances' => ['balance', null, 'snapshot_count', 'Snapshots'],
        ];
        $type = $this->option('type');
        if (! isset($types[$type])) {
            $this->error('Type must be sales-orders, invoices, credit-memos, payments, or balances.');

            return self::FAILURE;
        }
        [$prefix, $sourceType, $countKey, $label] = $types[$type];
        $isBalance = $type === 'balances';
        $completionKey = $isBalance ? 'snapshot_synced_at' : 'backfilled_at';
        $summaryCompletionKey = $isBalance ? 'snapshots' : 'backfilled';
        $totalKey = $isBalance ? 'stored_snapshots' : $prefix;
        $customerId = null;

        if ($this->option('customer') !== null) {
            $customerId = filter_var($this->option('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($customerId === false) {
                $this->error('Customer must be a positive NetSuite internal ID.');

                return self::FAILURE;
            }
        }

        $now = now()->toImmutable();
        $fields = ['id', 'name', 'account_number', 'is_active', 'portal_last_active_at'];
        foreach (['sync_started_at', 'synced_at', 'backfilled_at', 'next_sync_at', 'sync_error'] as $field) {
            if (! $isBalance || $field !== 'backfilled_at') {
                $fields[] = $prefix.'_'.$field;
            }
        }
        if ($type === 'sales-orders') {
            $fields = [...$fields, 'sales_orders_checkpoint_at', 'sales_orders_full_synced_at'];
        }
        $companies = Company::query()->select($fields)
            ->when($customerId !== null, fn (Builder $query) => $query->where('id', $customerId))
            ->when($customerId === null && ! $this->option('include-inactive'), fn (Builder $query) => $query->where('is_active', true))
            ->when($isBalance, fn (Builder $query) => $query->selectRaw('CASE WHEN account_balance_snapshot IS NULL THEN 0 ELSE 1 END AS snapshot_count'),
                fn (Builder $query) => $query->withCount(['transactions as '.$countKey => fn (Builder $query) => $query->where('type', $sourceType)]))
            ->orderBy('id')->get();

        if ($customerId !== null && $companies->isEmpty()) {
            $this->error('Customer is not registered in Milkstool. Run customer discovery first.');

            return self::FAILURE;
        }

        $rows = $companies->map(function (Company $company) use ($now, $prefix, $countKey, $isBalance, $completionKey): array {
            $completedAt = $isBalance
                ? ($company->snapshot_count ? $company->balance_synced_at : null)
                : $company->{$prefix.'_backfilled_at'};
            $unfinished = $company->{$prefix.'_sync_started_at'} !== null
                && ($company->{$prefix.'_synced_at'} === null || $company->{$prefix.'_sync_started_at'}->gt($company->{$prefix.'_synced_at'}));
            $due = $company->refreshDueAt($prefix)?->lte($now) ?? true;
            $status = match (true) {
                ! $company->is_active => 'inactive',
                $company->{$prefix.'_sync_error'} !== null => 'failed',
                $unfinished => 'unfinished_attempt',
                $company->{$prefix.'_synced_at'} === null => 'never_synced',
                $due => 'due',
                default => 'current',
            };

            return [
                'netsuite_id' => (int) $company->id,
                'account_number' => $company->account_number,
                'name' => $company->name,
                'active' => $company->is_active,
                'status' => $status,
                'needs_attention' => $company->is_active && ($status !== 'current' || $completedAt === null),
                $countKey => (int) $company->{$countKey},
                'last_attempt_at' => $this->timestamp($company->{$prefix.'_sync_started_at'}),
                'last_success_at' => $this->timestamp($company->{$prefix.'_synced_at'}),
                'source_checkpoint_at' => $prefix === 'sales_orders' ? $this->timestamp($company->sales_orders_checkpoint_at) : null,
                $completionKey => $this->timestamp($completedAt),
                'last_full_sync_at' => $this->timestamp($prefix === 'sales_orders' ? $company->sales_orders_full_synced_at : $company->{$prefix.'_synced_at'}),
                'next_sync_at' => $this->timestamp($company->refreshDueAt($prefix)),
                'error' => $company->{$prefix.'_sync_error'},
            ];
        })->when($this->option('attention'), fn ($rows) => $rows->where('needs_attention', true))->values();

        $report = [
            'type' => $type,
            'generated_at' => $this->timestamp($now),
            'scheduled_sync_enabled' => (bool) config('netsuite-sync.scheduled'),
            'summary' => [
                'customers' => $rows->count(),
                $summaryCompletionKey => $rows->whereNotNull($completionKey)->count(),
                'needs_attention' => $rows->where('needs_attention', true)->count(),
                $totalKey => $rows->sum($countKey),
                'by_status' => array_replace(array_fill_keys(['current', 'due', 'failed', 'never_synced', 'unfinished_attempt', 'inactive'], 0), $rows->countBy('status')->all()),
            ],
            'queue_scope' => 'global',
            'worker_liveness' => 'reported_by_health_check',
            'queues' => $this->queueCounts($now->getTimestamp()),
            'customers' => $rows->all(),
        ];

        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $summary = $report['summary'];
        $completionLabel = $isBalance ? 'Snapshot synced' : 'Backfilled';
        $summaryLabel = $type === 'sales-orders' ? 'sales orders' : strtolower($label);
        $this->line('Scheduled sync configuration: '.($report['scheduled_sync_enabled'] ? 'enabled' : 'disabled'));
        $this->line("Matching customers: {$summary['customers']}; {$summaryCompletionKey}: {$summary[$summaryCompletionKey]}; need attention: {$summary['needs_attention']}; {$summaryLabel}: {$summary[$totalKey]}.");
        $this->table(['NetSuite ID', 'Account', 'Customer', 'Status', $label, $completionLabel, 'Last success (UTC)', 'Next sync (UTC)'],
            $rows->map(fn (array $row): array => [
                $row['netsuite_id'], $row['account_number'] ?? '-', $row['name'], $row['status'], $row[$countKey],
                $row[$completionKey] === null ? 'No' : 'Yes', $row['last_success_at'] ?? '-', $row['next_sync_at'] ?? '-',
            ])->all());
        $this->table(['Queue (global)', 'Ready', 'Delayed', 'Reserved', 'Expired reservations', 'Failed'],
            array_map(fn (array $queue): array => array_map(fn ($value) => $value ?? 'Unavailable', $queue), $report['queues']));

        foreach ($rows->whereNotNull('error') as $row) {
            $this->line('Customer '.$row['netsuite_id'].': '.$row['error']);
        }

        if ($customerId !== null && $rows->isNotEmpty()) {
            $row = $rows->first();
            $this->line('Source checkpoint (UTC): '.($row['source_checkpoint_at'] ?? '-'));
            $this->line('Last full scan (UTC): '.($row['last_full_sync_at'] ?? '-'));
            $this->line(($isBalance ? 'Snapshot synced (UTC): ' : 'Backfill reconciled (UTC): ').($row[$completionKey] ?? '-'));
            $this->line('Last attempt (UTC): '.($row['last_attempt_at'] ?? '-'));
        }

        $this->line('Unfinished attempts may be running or interrupted. Reservations and configuration do not establish worker/scheduler liveness.');
        $this->line('Refresh one customer: php artisan milkstool:sync-'.($isBalance ? 'balance' : $type).' <ID> --queue');
        $this->line('Inspect failed jobs: php artisan queue:failed');
        $this->line('Check scheduler and worker heartbeats: php artisan milkstool:health');

        return self::SUCCESS;
    }

    private function timestamp(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->toIso8601String();
    }

    /** @return list<array{name: string, ready: ?int, delayed: ?int, reserved: ?int, expired_reservations: ?int, failed: ?int}> */
    private function queueCounts(int $now): array
    {
        $rows = [];

        foreach (['customers', 'sales-orders', 'invoices', 'credit-memos', 'balances', 'payments'] as $name) {
            $row = ['name' => $name, 'ready' => null, 'delayed' => null, 'reserved' => null, 'expired_reservations' => null, 'failed' => null];

            if (config('queue.connections.netsuite.driver') === 'database') {
                $query = DB::connection(config('queue.connections.netsuite.connection'))
                    ->table(config('queue.connections.netsuite.table'))->where('queue', $name);
                $row['ready'] = (clone $query)->whereNull('reserved_at')->where('available_at', '<=', $now)->count();
                $row['delayed'] = (clone $query)->whereNull('reserved_at')->where('available_at', '>', $now)->count();
                $row['reserved'] = (clone $query)->whereNotNull('reserved_at')->count();
                $row['expired_reservations'] = (clone $query)->whereNotNull('reserved_at')
                    ->where('reserved_at', '<=', $now - (int) config('queue.connections.netsuite.retry_after'))->count();
            }

            if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
                $row['failed'] = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'))
                    ->where('connection', 'netsuite')->where('queue', $name)->count();
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
