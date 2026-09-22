<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RecordWorkerHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $queueName)
    {
        $this->onConnection('netsuite');
        $this->onQueue($queueName);
    }

    public function uniqueId(): string
    {
        return $this->queueName;
    }

    public function handle(): void
    {
        Cache::put('milkstool:heartbeat:worker:'.$this->queueName, now()->timestamp, 3600);
    }
}
