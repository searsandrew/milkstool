<?php

namespace App\Console\Commands;

use App\Actions\SyncSalesOrders;
use App\Jobs\RefreshSalesOrders;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Searsandrew\BriarRose\Exceptions\BriarRoseConfigurationException;

class SyncSalesOrdersCommand extends Command
{
    protected $signature = 'milkstool:sync-sales-orders {customer : NetSuite customer internal ID} {--resume : Reuse saved orders whose source headers still match} {--incremental : Refresh changed orders, with an initial and weekly full scan} {--queue : Queue an incremental refresh instead of running now}';

    protected $description = 'Import all sales orders and lines for one NetSuite customer (read-only in NetSuite)';

    public function handle(SyncSalesOrders $sync): int
    {
        $customerId = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($customerId === false) {
            $this->error('Customer must be a positive NetSuite internal ID.');

            return self::FAILURE;
        }

        if ($this->option('resume') && ($this->option('incremental') || $this->option('queue'))) {
            $this->error('Use --resume only with a full foreground import.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            RefreshSalesOrders::dispatch($customerId);
            $this->info('Background refresh requested for NetSuite customer '.$customerId.'. Duplicate pending requests are ignored.');

            return self::SUCCESS;
        }

        $this->info('Importing sales orders for NetSuite customer '.$customerId.'.');

        try {
            $counts = $sync->handle($customerId, function (int $orders, int $lines): void {
                $this->line("Processed {$orders} orders / {$lines} lines.");
            }, resume: (bool) $this->option('resume'), incremental: (bool) $this->option('incremental'));
        } catch (RequestException $exception) {
            $this->error('NetSuite returned HTTP '.$exception->response->status().'. The import did not complete.');

            return self::FAILURE;
        } catch (ConnectionException) {
            $this->error('Could not connect to NetSuite. The import did not complete.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error('NetSuite returned invalid data: '.implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (BriarRoseConfigurationException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('incremental')
            ? "Refresh complete: {$counts['orders']} orders, {$counts['lines']} lines processed. Source checkpoint saved."
            : "Sync complete: {$counts['orders']} orders, {$counts['lines']} lines. Local order count matches this import.");

        return self::SUCCESS;
    }
}
