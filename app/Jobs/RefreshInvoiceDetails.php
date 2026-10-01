<?php

namespace App\Jobs;

use App\Actions\SyncInvoice;
use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshInvoiceDetails implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;
    use WaitsForNetSuite;

    public int $tries = 3;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public function __construct(public int $customerId)
    {
        $this->onConnection('netsuite');
        $this->onQueue('invoices');
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

    public function handle(SyncInvoice $sync): void
    {
        $company = Company::query()->where('is_active', true)->find($this->customerId);
        if ($company === null) {
            return;
        }
        try {
            $ids = $company->transactions()->needsInvoiceEnrichment()->orderByDesc('transaction_date')->orderByDesc('id')->limit(5)->pluck('id');
            foreach ($ids as $id) {
                $sync->handle($this->customerId, (int) $id);
            }
            if ($company->transactions()->needsInvoiceEnrichment()->exists()) {
                self::dispatch($this->customerId)->delay(now()->addSeconds(30));
            }
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

    public function failed(?Throwable $exception): void
    {
        Log::error('Invoice enrichment failed; pending invoices remain eligible for the next customer refresh.', ['customer_id' => $this->customerId]);
    }
}
