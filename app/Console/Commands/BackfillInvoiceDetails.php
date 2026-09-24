<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\NetSuite\InvoiceSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BackfillInvoiceDetails extends Command
{
    protected $signature = 'milkstool:backfill-invoice-details {--limit=3 : Maximum active customers to process} {--dry-run : Preview without contacting NetSuite}';

    protected $description = 'Fill missing invoice addresses and terms without changing financial sync freshness';

    public function handle(InvoiceSource $source): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be an integer between 1 and 1000.');

            return self::FAILURE;
        }
        $backfillLock = Cache::lock('netsuite-invoice-details-backfill', 86400);
        if (! $backfillLock->get()) {
            $this->error('An invoice details backfill is already running.');

            return self::FAILURE;
        }
        try {
            $customers = Company::query()->where('is_active', true)
                ->whereHas('transactions', fn (Builder $query) => $query->where('type', 'CustInvc')->whereNull('invoice_details_synced_at'))
                ->orderBy('id')->limit($limit)->get(['id']);
            $completed = 0;
            foreach ($customers as $customer) {
                if (! $backfillLock->refresh(86400) && ! $backfillLock->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('The backfill lock expired.');
                }
                $this->line('Invoice details for customer '.$customer->id);
                if ($this->option('dry-run')) {
                    continue;
                }
                $lock = Cache::lock('netsuite-invoices:'.$customer->id, 600);
                if (! $lock->get()) {
                    $this->error('An invoice sync is already running for this customer. Rerun to resume.');

                    return self::FAILURE;
                }
                try {
                    $customer->transactions()->where('type', 'CustInvc')->whereNull('invoice_details_synced_at')
                        ->chunkById(50, function ($invoices) use ($customer, $source, $lock, &$completed): void {
                            $ids = $invoices->pluck('id')->map(fn ($id): int => (int) $id)->all();
                            $headers = $source->invoicesByIds((int) $customer->id, $ids);
                            $verified = $source->invoicesByIds((int) $customer->id, $ids);
                            if ($headers != $verified) {
                                throw new RuntimeException('Invoice headers changed during retrieval.');
                            }
                            if (! $lock->refresh(600) && ! $lock->isOwnedByCurrentProcess()) {
                                throw new RuntimeException('The customer invoice lock expired.');
                            }
                            DB::transaction(function () use ($invoices, $headers): void {
                                foreach ($invoices as $invoice) {
                                    $invoice->fillInvoiceDetails($headers[(int) $invoice->id]);
                                    $invoice->save();
                                }
                            });
                            $completed += $invoices->count();
                            $this->line('Imported '.$completed.' invoice details.');
                        });
                } catch (Throwable $exception) {
                    $reason = match (true) {
                        $exception instanceof RequestException => 'NetSuite HTTP '.$exception->response->status(),
                        $exception instanceof ValidationException => 'Invalid invoice header fields',
                        $exception instanceof RuntimeException => $exception->getMessage(),
                        default => class_basename($exception),
                    };
                    $this->error('Invoice details backfill stopped at customer '.$customer->id.' ('.$reason.'). Completed invoices will be skipped when rerun.');

                    return self::FAILURE;
                } finally {
                    $lock->release();
                }
            }
            $this->info($this->option('dry-run') ? 'Preview complete. No invoices changed.' : 'Invoice details backfill complete: '.$completed.' invoices updated.');

            return self::SUCCESS;
        } finally {
            $backfillLock->release();
        }
    }
}
