<?php

namespace App\Console\Commands;

use App\Jobs\RecordWorkerHeartbeat;
use App\Services\ServiceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecordSyncHeartbeat extends Command
{
    protected $signature = 'milkstool:heartbeat';

    protected $description = 'Record a scheduler heartbeat and queue deduplicated probes for each sync queue';

    public function handle(): int
    {
        if (! in_array(config('queue.connections.netsuite.driver'), ['database', 'redis'], true)) {
            $this->error('Heartbeat probes require an asynchronous database or Redis queue.');

            return self::FAILURE;
        }
        foreach (ServiceHealth::QUEUES as $queue) {
            RecordWorkerHeartbeat::dispatch($queue);
        }
        Cache::put('milkstool:heartbeat:scheduler', now()->timestamp, 3600);
        $this->info('Scheduler heartbeat recorded; worker probes queued.');

        return self::SUCCESS;
    }
}
