<?php

namespace App\Console\Commands;

use App\Jobs\RefreshInvoiceDetails;
use App\Services\InvoiceEnrichmentStatus;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class InvoiceEnrichment extends Command
{
    protected $signature = 'milkstool:invoice-enrichment
        {--customer= : Limit to one active NetSuite customer}
        {--queue : Queue pending work, including failed attempts}
        {--limit=5 : Maximum customers to queue, between 1 and 1000}
        {--json : Output machine-readable results}';

    protected $description = 'Audit invoice summary and tracking completeness locally, optionally queueing bounded recovery';

    public function handle(InvoiceEnrichmentStatus $status): int
    {
        $customer = $this->option('customer') === null ? null : filter_var($this->option('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($customer === false || $limit === false) {
            $this->error('Provide a positive customer ID and a limit between 1 and 1000.');

            return self::FAILURE;
        }
        $report = $status->report($customer);
        if ($customer !== null && $report['customers'] === []) {
            $this->error('Active customer was not found.');

            return self::FAILURE;
        }
        $report['recovery_requested'] = [];
        if ($this->option('queue')) {
            $candidates = collect($report['customers'])->filter(fn (array $row): bool => $row['summary_pending'] > 0 || $row['tracking_due'] > 0)->take($limit)->values();
            foreach ($candidates as $index => $row) {
                RefreshInvoiceDetails::dispatch($row['customer_id'])->delay(now()->addSeconds($index * 30));
                $report['recovery_requested'][] = $row['customer_id'];
            }
        }
        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->table(['Customer', 'Invoices', 'Summary pending', 'Tracking due', 'Last-attempt errors'], $report['customers']);
            $this->line('Summary pending: '.$report['summary_pending'].'; tracking due: '.$report['tracking_due'].'; last-attempt errors: '.$report['last_attempt_errors'].'.');
            $this->line('Retained enrichment failures (global): '.$report['retained_enrichment_failures_global']);
            $this->line('Recovery requested for '.count($report['recovery_requested']).' customers. Already queued work is deduplicated.');
            $this->line('Tracking covers related sales-order shipments. This audit reads local completeness, not live NetSuite reconciliation.');
        }

        return self::SUCCESS;
    }
}
