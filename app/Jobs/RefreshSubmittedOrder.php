<?php

namespace App\Jobs;

use App\Actions\SyncSalesOrders;
use App\Exceptions\SalesOrderSyncInterrupted;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

class RefreshSubmittedOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $expiresAt;

    public function __construct(public int $customerId, public int $orderId)
    {
        $this->expiresAt = now()->addMinutes(15)->timestamp;
        $this->onConnection('netsuite');
        $this->onQueue('sales-orders');
    }

    public function uniqueId(): string
    {
        return $this->customerId.':'.$this->orderId;
    }

    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->expiresAt);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(SyncSalesOrders $sync): void
    {
        if (! Company::query()->where('netsuite_id', $this->customerId)->where('is_active', true)->exists()) {
            return;
        }
        if (now()->timestamp >= $this->expiresAt) {
            $this->fail(new RuntimeException('The submitted order refresh window expired. Request another refresh or inspect NetSuite visibility.'));

            return;
        }
        try {
            if ($sync->syncOrder($this->customerId, $this->orderId) === null) {
                $this->release(60);

                return;
            }
            RefreshCustomerBalance::dispatch($this->customerId);
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
}
