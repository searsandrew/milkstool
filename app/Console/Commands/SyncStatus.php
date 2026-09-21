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
        {--customer= : NetSuite internal ID, including inactive customers}
        {--attention : Show only active customers with unfinished backfills or refreshes needing attention}
        {--include-inactive : Include inactive customers in the list}
        {--json : Output a machine-readable report}';

    protected $description = 'Show local sales-order sync freshness, backfill completion, and queue counts without contacting NetSuite';

    public function handle(): int
    {
        $customerId = null;

        if ($this->option('customer') !== null) {
            $customerId = filter_var($this->option('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($customerId === false) {
                $this->error('Customer must be a positive NetSuite internal ID.');

                return self::FAILURE;
            }
        }

        $now = now()->toImmutable();
        $companies = Company::query()->select([
            'id', 'netsuite_id', 'name', 'account_number', 'is_active', 'sales_orders_sync_started_at',
            'sales_orders_synced_at', 'sales_orders_checkpoint_at', 'sales_orders_backfilled_at',
            'sales_orders_full_synced_at', 'sales_orders_next_sync_at', 'sales_orders_sync_error',
        ])->when($customerId !== null, fn (Builder $query) => $query->where('netsuite_id', $customerId))
            ->when($customerId === null && ! $this->option('include-inactive'), fn (Builder $query) => $query->where('is_active', true))
            ->withCount(['transactions as sales_order_count' => fn (Builder $query) => $query->where('type', 'SalesOrd')])
            ->orderBy('netsuite_id')->get();

        if ($customerId !== null && $companies->isEmpty()) {
            $this->error('Customer is not registered in Milkstool. Run customer discovery first.');

            return self::FAILURE;
        }

        $rows = $companies->map(function (Company $company) use ($now): array {
            $unfinished = $company->sales_orders_sync_started_at !== null
                && ($company->sales_orders_synced_at === null || $company->sales_orders_sync_started_at->gt($company->sales_orders_synced_at));
            $due = ($company->sales_orders_next_sync_at ?? $company->sales_orders_synced_at?->addHours(6))?->lte($now) ?? true;
            $status = match (true) {
                ! $company->is_active => 'inactive',
                $company->sales_orders_sync_error !== null => 'failed',
                $unfinished => 'unfinished_attempt',
                $company->sales_orders_synced_at === null => 'never_synced',
                $due => 'due',
                default => 'current',
            };

            return [
                'netsuite_id' => (int) $company->netsuite_id,
                'account_number' => $company->account_number,
                'name' => $company->name,
                'active' => $company->is_active,
                'status' => $status,
                'needs_attention' => $company->is_active && ($status !== 'current' || $company->sales_orders_backfilled_at === null),
                'sales_order_count' => (int) $company->sales_order_count,
                'last_attempt_at' => $this->timestamp($company->sales_orders_sync_started_at),
                'last_success_at' => $this->timestamp($company->sales_orders_synced_at),
                'source_checkpoint_at' => $this->timestamp($company->sales_orders_checkpoint_at),
                'backfilled_at' => $this->timestamp($company->sales_orders_backfilled_at),
                'last_full_sync_at' => $this->timestamp($company->sales_orders_full_synced_at),
                'next_sync_at' => $this->timestamp($company->sales_orders_next_sync_at),
                'error' => $company->sales_orders_sync_error,
            ];
        })->when($this->option('attention'), fn ($rows) => $rows->where('needs_attention', true))->values();

        $report = [
            'generated_at' => $this->timestamp($now),
            'scheduled_sync_enabled' => (bool) config('netsuite-sync.scheduled'),
            'summary' => [
                'customers' => $rows->count(),
                'backfilled' => $rows->whereNotNull('backfilled_at')->count(),
                'needs_attention' => $rows->where('needs_attention', true)->count(),
                'sales_orders' => $rows->sum('sales_order_count'),
                'by_status' => array_replace(array_fill_keys(['current', 'due', 'failed', 'never_synced', 'unfinished_attempt', 'inactive'], 0), $rows->countBy('status')->all()),
            ],
            'queue_scope' => 'global',
            'worker_liveness' => 'not_monitored',
            'queues' => $this->queueCounts($now->getTimestamp()),
            'customers' => $rows->all(),
        ];

        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $summary = $report['summary'];
        $this->line('Scheduled sync configuration: '.($report['scheduled_sync_enabled'] ? 'enabled' : 'disabled'));
        $this->line("Matching customers: {$summary['customers']}; backfilled: {$summary['backfilled']}; need attention: {$summary['needs_attention']}; sales orders: {$summary['sales_orders']}.");
        $this->table(['NetSuite ID', 'Account', 'Customer', 'Status', 'Orders', 'Backfilled', 'Last success (UTC)', 'Next sync (UTC)'],
            $rows->map(fn (array $row): array => [
                $row['netsuite_id'], $row['account_number'] ?? '-', $row['name'], $row['status'], $row['sales_order_count'],
                $row['backfilled_at'] === null ? 'No' : 'Yes', $row['last_success_at'] ?? '-', $row['next_sync_at'] ?? '-',
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
            $this->line('Backfill reconciled (UTC): '.($row['backfilled_at'] ?? '-'));
            $this->line('Last attempt (UTC): '.($row['last_attempt_at'] ?? '-'));
        }

        $this->line('Unfinished attempts may be running or interrupted. Reservations and configuration do not establish worker/scheduler liveness.');
        $this->line('Refresh one customer: php artisan milkstool:sync-sales-orders <ID> --queue');
        $this->line('Inspect failed jobs: php artisan queue:failed');

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

        foreach (['customers', 'sales-orders'] as $name) {
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
