<?php

namespace App\Console\Commands;

use App\Actions\ReconcileSalesOrders;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Searsandrew\BriarRose\Exceptions\BriarRoseConfigurationException;

class ReconcileSalesOrdersCommand extends Command
{
    protected $signature = 'milkstool:reconcile-sales-orders {customer : NetSuite customer internal ID}';

    protected $description = 'Compare local and NetSuite sales-order counts and signed totals by currency without modifying transactions';

    public function handle(ReconcileSalesOrders $reconcile): int
    {
        $customerId = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($customerId === false) {
            $this->error('Customer must be a positive NetSuite internal ID.');

            return self::FAILURE;
        }

        try {
            $results = $reconcile->handle($customerId);
        } catch (RequestException $exception) {
            $this->error('NetSuite returned HTTP '.$exception->response->status().'. Reconciliation did not complete.');

            return self::FAILURE;
        } catch (ConnectionException) {
            $this->error('Could not connect to NetSuite. Reconciliation did not complete.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error('NetSuite returned invalid control totals: '.implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (BriarRoseConfigurationException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Currency ID', 'Metric', 'NetSuite', 'Milkstool', 'Result'],
            array_map(fn (array $row): array => [
                $row['currency_id'], $row['metric'], $row['source'], $row['local'], $row['matches'] ? 'Match' : 'MISMATCH',
            ], $results),
        );

        if (collect($results)->contains('matches', false)) {
            $this->error('Control totals differ. NetSuite may have changed since the import; investigate before trusting this snapshot.');

            return self::FAILURE;
        }

        $this->info('Control totals match. This is an aggregate check, not a field-by-field audit or a point-in-time snapshot.');

        return self::SUCCESS;
    }
}
