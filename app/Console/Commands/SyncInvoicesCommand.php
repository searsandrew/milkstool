<?php

namespace App\Console\Commands;

use App\Actions\SyncInvoices;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Searsandrew\BriarRose\Exceptions\BriarRoseConfigurationException;

class SyncInvoicesCommand extends Command
{
    protected $signature = 'milkstool:sync-invoices {customer : NetSuite customer internal ID}';

    protected $description = 'Import and reconcile all invoices for one customer (read-only in NetSuite)';

    public function handle(SyncInvoices $sync): int
    {
        $options = ['options' => ['min_range' => 1]];
        $customer = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, $options);

        if ($customer === false) {
            $this->error('Customer must be a positive NetSuite internal ID.');

            return self::FAILURE;
        }

        try {
            $result = $sync->handle($customer, function (int $invoices, int $lines): void {
                $this->line("Processed {$invoices} invoices / {$lines} lines.");
            });
        } catch (RequestException $exception) {
            $this->error('NetSuite returned HTTP '.$exception->response->status().'. Invoice import did not complete.');

            return self::FAILURE;
        } catch (ConnectionException) {
            $this->error('Could not connect to NetSuite. Invoice import did not complete.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error('NetSuite returned invalid invoice data: '.implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (BriarRoseConfigurationException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Currency', 'Metric', 'NetSuite', 'Milkstool'], array_map(fn (array $row): array => [
            $row['currency_id'], $row['metric'], $row['source'], $row['local'],
        ], $result['reconciliation']));
        $this->info("Sync complete: {$result['invoices']} invoices, {$result['lines']} lines. All control totals match.");
        $this->line('Per-customer invoice aggregate check, not a point-in-time snapshot or complete account balance.');

        return self::SUCCESS;
    }
}
