<?php

namespace App\Jobs;

use App\Actions\SyncSalesOrderDetails;
use App\Models\Company;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshSalesOrderDetails implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use WaitsForNetSuite;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(public int $customerId, public int $orderId)
    {
        $this->onConnection('netsuite');
        $this->onQueue('sales-orders');
    }

    public function uniqueId(): string
    {
        return $this->customerId.':'.$this->orderId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SyncSalesOrderDetails $sync): void
    {
        if (Company::query()->whereKey($this->customerId)->where('is_active', true)->exists()) {
            $sync->handle($this->customerId, $this->orderId);
        }
    }
}
