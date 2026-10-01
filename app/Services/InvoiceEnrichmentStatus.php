<?php

namespace App\Services;

use App\Jobs\RefreshInvoiceDetails;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

class InvoiceEnrichmentStatus
{
    /** @param list<int> $invoiceIds */
    public function recordFailure(int $customerId, array $invoiceIds, string $component, Throwable $exception): void
    {
        $query = Transaction::query()->where('company_id', $customerId)->where('type', 'CustInvc')->whereIn('id', $invoiceIds);
        (clone $query)->whereNull('invoice_details')->update(['invoice_details' => ['enrichment_error' => null]]);
        $query->update(['invoice_details->enrichment_error' => [
            'component' => $component,
            'message' => 'The last '.$component.' refresh failed. Retry enrichment or inspect the failed queue job.',
            'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
            'attempted_at' => now()->utc()->toIso8601String(),
        ]]);
    }

    /** @return array<string, mixed> */
    public function report(?int $customerId = null): array
    {
        $rows = Company::query()->where('is_active', true)->when($customerId !== null, fn (Builder $query) => $query->whereKey($customerId))
            ->select('id')->withCount([
                'transactions as invoices' => fn (Builder $query) => $query->where('type', 'CustInvc'),
                'transactions as summary_pending' => fn (Builder $query) => $query->needsInvoiceEnrichment(),
                'transactions as tracking_due' => fn (Builder $query) => $query->needsInvoiceTracking(),
                'transactions as last_attempt_errors' => fn (Builder $query) => $query->needsInvoiceWork()->whereNotNull('invoice_details->enrichment_error'),
            ])->orderBy('id')->get()->map(fn (Company $company): array => [
                'customer_id' => (int) $company->id, 'invoices' => (int) $company->invoices,
                'summary_pending' => (int) $company->summary_pending, 'tracking_due' => (int) $company->tracking_due,
                'last_attempt_errors' => (int) $company->last_attempt_errors,
            ]);

        return [
            'retained_enrichment_failures_global' => DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'))
                ->where('connection', 'netsuite')->where('payload->displayName', RefreshInvoiceDetails::class)->count(),
            'generated_at' => now()->utc()->toIso8601String(), 'scope' => 'active_customers',
            'complete' => $rows->sum('summary_pending') === 0 && $rows->sum('tracking_due') === 0,
            'invoices' => $rows->sum('invoices'), 'summary_pending' => $rows->sum('summary_pending'),
            'tracking_due' => $rows->sum('tracking_due'), 'last_attempt_errors' => $rows->sum('last_attempt_errors'),
            'customers' => $rows->all(),
        ];
    }
}
