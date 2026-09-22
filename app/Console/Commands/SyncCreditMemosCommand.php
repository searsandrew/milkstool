<?php

namespace App\Console\Commands;

use App\Actions\SyncCreditMemos;
use App\Jobs\RefreshCreditMemos;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Searsandrew\BriarRose\Exceptions\BriarRoseConfigurationException;

class SyncCreditMemosCommand extends Command
{
    protected $signature = 'milkstool:sync-credit-memos {customer : NetSuite customer internal ID} {--queue : Queue a background import}';

    protected $description = 'Import and reconcile all credit memos for one customer (read-only in NetSuite)';

    public function handle(SyncCreditMemos $sync): int
    {
        $options = ['options' => ['min_range' => 1]];
        $customer = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, $options);

        if ($customer === false) {
            $this->error('Customer must be a positive NetSuite internal ID.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            RefreshCreditMemos::dispatch($customer);
            $this->info('Credit memo refresh requested; already queued customers are deduplicated.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->handle($customer, function (int $creditMemos, int $lines): void {
                $this->line("Processed {$creditMemos} credit memos / {$lines} lines.");
            });
        } catch (RequestException $exception) {
            $this->error('NetSuite returned HTTP '.$exception->response->status().'. Credit memo import did not complete.');

            return self::FAILURE;
        } catch (ConnectionException) {
            $this->error('Could not connect to NetSuite. Credit memo import did not complete.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error('NetSuite returned invalid credit memo data: '.implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (BriarRoseConfigurationException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Currency', 'Metric', 'NetSuite', 'Milkstool'], array_map(fn (array $row): array => [
            $row['currency_id'], $row['metric'], $row['source'], $row['local'],
        ], $result['reconciliation']));
        $this->info("Sync complete: {$result['creditMemos']} credit memos, {$result['lines']} lines. All control totals match.");
        $this->line('Per-customer credit memo aggregate check, not a point-in-time snapshot or complete account balance.');

        return self::SUCCESS;
    }
}
