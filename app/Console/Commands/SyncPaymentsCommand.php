<?php

namespace App\Console\Commands;

use App\Actions\SyncPayments;
use App\Jobs\RefreshPayments;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Searsandrew\BriarRose\Exceptions\BriarRoseConfigurationException;

class SyncPaymentsCommand extends Command
{
    protected $signature = 'milkstool:sync-payments {customer : NetSuite customer internal ID} {--queue : Queue a background import}';

    protected $description = 'Import and reconcile all payments for one customer (read-only in NetSuite)';

    public function handle(SyncPayments $sync): int
    {
        $options = ['options' => ['min_range' => 1]];
        $customer = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, $options);

        if ($customer === false) {
            $this->error('Customer must be a positive NetSuite internal ID.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            RefreshPayments::dispatch($customer);
            $this->info('Payment refresh requested; already queued customers are deduplicated.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->handle($customer, function (int $payments, int $lines): void {
                $this->line("Processed {$payments} payments / {$lines} lines.");
            });
        } catch (RequestException $exception) {
            $this->error('NetSuite returned HTTP '.$exception->response->status().'. Payment import did not complete.');

            return self::FAILURE;
        } catch (ConnectionException) {
            $this->error('Could not connect to NetSuite. Payment import did not complete.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error('NetSuite returned invalid payment data: '.implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (BriarRoseConfigurationException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Currency', 'Metric', 'NetSuite', 'Milkstool'], array_map(fn (array $row): array => [
            $row['currency_id'], $row['metric'], $row['source'], $row['local'],
        ], $result['reconciliation']));
        $this->info("Sync complete: {$result['payments']} payments, {$result['lines']} lines. All control totals match.");
        $this->line('Per-customer payment aggregate check, not a point-in-time snapshot or complete account balance.');

        return self::SUCCESS;
    }
}
