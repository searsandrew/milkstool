<?php

namespace App\Jobs;

use App\Actions\SyncInvoice;
use App\Actions\SyncInvoiceTracking;
use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Services\InvoiceEnrichmentStatus;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshInvoiceDetails implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;
    use WaitsForNetSuite {
        middleware as private netSuiteMiddleware;
    }

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public function __construct(public int $customerId)
    {
        $this->onConnection('netsuite');
        $this->onQueue('invoice-enrichment');
    }

    /** @return list<WithoutOverlapping|NetSuiteCooldown> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('invoice-work:'.$this->customerId))->shared()->releaseAfter(60)->expireAfter(1260),
            ...$this->netSuiteMiddleware()];
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

    public function handle(SyncInvoice $sync, SyncInvoiceTracking $tracking, InvoiceEnrichmentStatus $status): void
    {
        $company = Company::query()->where('is_active', true)->find($this->customerId);
        if ($company === null) {
            return;
        }
        $ids = [];
        $currentId = null;
        $component = 'summary';
        try {
            $invoices = $company->transactions()->needsInvoiceWork()
                ->orderBy('invoice_details->tracking_synced_at')->orderByDesc('transaction_date')->orderByDesc('id')->limit(5)->get();
            $ids = $invoices->modelKeys();
            foreach ($invoices as $invoice) {
                $currentId = (int) $invoice->id;
                if (! $invoice->hasCurrentInvoiceEnrichment()) {
                    $sync->handle($this->customerId, $currentId);
                }
            }
            if ($ids !== []) {
                $component = 'tracking';
                $tracking->handle($this->customerId, $ids);
            }
            if ($company->transactions()->needsInvoiceWork()->exists()) {
                self::dispatch($this->customerId)->delay(now()->addSeconds(30));
            }
            Cache::put('milkstool:heartbeat:worker:invoice-enrichment', now()->timestamp, 3600);
        } catch (Throwable $exception) {
            $status->recordFailure($this->customerId, $component === 'tracking' ? $ids : ($currentId === null ? [] : [$currentId]), $component, $exception);
            if ($exception instanceof ConnectionException || $exception instanceof ReceivableSyncInterrupted
                || ($exception instanceof RequestException && (in_array($exception->response->status(), [408, 429], true) || $exception->response->serverError()))) {
                throw $exception;
            }
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Invoice enrichment failed; pending invoices remain eligible for the next customer refresh.', ['customer_id' => $this->customerId]);
    }
}
