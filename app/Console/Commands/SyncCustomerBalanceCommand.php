<?php

namespace App\Console\Commands;

use App\Actions\SyncCustomerBalance;
use App\Jobs\RefreshCustomerBalance;
use Brick\Math\Exception\MathException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Searsandrew\BriarRose\Exceptions\BriarRoseConfigurationException;

class SyncCustomerBalanceCommand extends Command
{
    protected $signature = 'milkstool:sync-balance {customer : NetSuite customer internal ID} {--queue : Queue a background refresh}';

    protected $description = 'Mirror NetSuite customer balance fields without calculating them from invoices';

    public function handle(SyncCustomerBalance $sync): int
    {
        $id = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->error('Customer must be a positive NetSuite internal ID.');

            return self::FAILURE;
        }
        if ($this->option('queue')) {
            RefreshCustomerBalance::dispatch($id);
            $this->info('Customer balance refresh requested.');

            return self::SUCCESS;
        }
        try {
            $snapshot = $sync->handle($id);
        } catch (RequestException $exception) {
            $this->error('NetSuite returned HTTP '.$exception->response->status().'. Balance refresh did not complete.');

            return self::FAILURE;
        } catch (ConnectionException|ValidationException|BriarRoseConfigurationException|MathException $exception) {
            $this->error('NetSuite balance data could not be retrieved or validated. The last successful snapshot was retained.');

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->table(['Field', 'NetSuite value'], collect($snapshot)->map(fn ($value, $key): array => [$key, $value ?? 'Not supplied'])->values()->all());
        $this->info('Customer balance snapshot refreshed.');

        return self::SUCCESS;
    }
}
