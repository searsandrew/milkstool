<?php

namespace App\Jobs;

use App\Actions\SyncSalesOrders;
use App\Exceptions\SalesOrderSyncInterrupted;
use App\Models\Company;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class RefreshSalesOrders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public string $requestedAt;

    public function __construct(public int $customerId)
    {
        $this->requestedAt = now()->format('Y-m-d H:i:s');
        $this->onConnection('netsuite');
        $this->onQueue('sales-orders');
    }

    public function uniqueId(): string
    {
        return (string) $this->customerId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SyncSalesOrders $sync): void
    {
        if (Company::query()->where('id', $this->customerId)->where('is_active', false)->exists()) {
            return;
        }

        try {
            $sync->handle($this->customerId, incremental: true);
        } catch (ConnectionException|SalesOrderSyncInterrupted $exception) {
            throw $exception;
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [408, 429], true) || $exception->response->serverError()) {
                throw $exception;
            }

            $this->fail($exception);
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Company::query()->where('id', $this->customerId)
            ->where(fn (Builder $query) => $query->whereNull('sales_orders_synced_at')->orWhere('sales_orders_synced_at', '<=', $this->requestedAt))
            ->update([
                'sales_orders_sync_error' => 'Background refresh failed. Inspect failed queue jobs before retrying.',
                'sales_orders_next_sync_at' => now()->addHours(6),
            ]);
    }
}
