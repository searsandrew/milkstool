<?php

namespace App\Console\Commands;

use App\Jobs\RefreshCreditMemos;
use App\Jobs\RefreshCustomerBalance;
use App\Jobs\RefreshCustomers;
use App\Jobs\RefreshInvoiceDetails;
use App\Jobs\RefreshInvoices;
use App\Jobs\RefreshPayments;
use App\Jobs\RefreshSalesOrders;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairQueuedSyncs extends Command
{
    protected $signature = 'milkstool:repair-queued-syncs {--dry-run : Preview changes without updating waiting jobs}';

    protected $description = 'Remove legacy waiting-job deadlines and route invoice history without touching reserved jobs or imported data';

    public function handle(): int
    {
        if (config('queue.connections.netsuite.driver') !== 'database') {
            $this->error('This repair supports the database queue only.');

            return self::FAILURE;
        }
        $classes = [RefreshCustomers::class, RefreshCustomerBalance::class, RefreshSalesOrders::class,
            RefreshInvoices::class, RefreshInvoiceDetails::class, RefreshCreditMemos::class, RefreshPayments::class];
        $database = DB::connection(config('queue.connections.netsuite.connection'));
        $table = config('queue.connections.netsuite.table');
        $count = 0;
        $upperId = $database->table($table)->max('id');
        foreach ($database->table($table)->whereNull('reserved_at')->where('id', '<=', $upperId)->orderBy('id')->lazyById(100) as $candidate) {
            $count += $database->transaction(function () use ($database, $table, $candidate, $classes): int {
                $row = $database->table($table)->where('id', $candidate->id)->whereNull('reserved_at')->lockForUpdate()->first();
                if ($row === null) {
                    return 0;
                }
                $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $class = $payload['data']['commandName'] ?? null;
                if (! in_array($class, $classes, true)) {
                    return 0;
                }
                $job = unserialize($payload['data']['command'], ['allowed_classes' => [...$classes, Carbon::class, CarbonImmutable::class, \DateTime::class, \DateTimeImmutable::class, \DateTimeZone::class]]);
                if (! $job instanceof $class || $job->connection !== 'netsuite') {
                    return 0;
                }
                $job->tries = 0;
                if ($job instanceof RefreshInvoices) {
                    $job->onQueue((new RefreshInvoices($job->customerId))->queue);
                }
                $payload['maxTries'] = 0;
                $payload['retryUntil'] = null;
                $payload['data']['command'] = serialize($job);
                $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
                if ($encoded === $row->payload && $job->queue === $row->queue) {
                    return 0;
                }
                if (! $this->option('dry-run')) {
                    $database->table($table)->where('id', $row->id)->update(['payload' => $encoded, 'queue' => $job->queue]);
                }

                return 1;
            });
        }
        $this->info($count.' waiting jobs '.($this->option('dry-run') ? 'would be updated.' : 'updated.'));
        $this->line('Reserved jobs, failed-job evidence, retry counters, and imported data were preserved.');

        return self::SUCCESS;
    }
}
