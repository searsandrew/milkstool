<?php

namespace App\Jobs;

use App\Actions\SyncInvoice;
use App\Actions\SyncInvoiceTracking;
use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RefreshRequestedInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use WaitsForNetSuite {
        middleware as private netSuiteMiddleware;
    }

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $expiresAt;

    public function __construct(public int $customerId, public int $invoiceId)
    {
        $this->expiresAt = now()->addMinutes(15)->timestamp;
        $this->onConnection('netsuite');
        $this->onQueue('interactive-refreshes');
    }

    public function uniqueId(): string
    {
        return $this->customerId.':'.$this->invoiceId;
    }

    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->expiresAt);
    }

    /** @return list<WithoutOverlapping|NetSuiteCooldown> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('invoice-work:'.$this->customerId))->shared()->releaseAfter(15)->expireAfter(1260), ...$this->netSuiteMiddleware()];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(SyncInvoice $sync, SyncInvoiceTracking $tracking): void
    {
        if (! Company::query()->whereKey($this->customerId)->where('is_active', true)->exists()
            || ! Transaction::query()->whereKey($this->invoiceId)->where('company_id', $this->customerId)->where('type', 'CustInvc')->exists()) {
            return;
        }
        try {
            $sync->handle($this->customerId, $this->invoiceId);
            $tracking->handle($this->customerId, [$this->invoiceId]);
        } catch (ConnectionException|ReceivableSyncInterrupted $exception) {
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
