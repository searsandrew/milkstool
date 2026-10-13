<?php

namespace App\Console\Commands;

use App\Actions\SyncReturnAuthorizations;
use App\Jobs\RefreshReturnAuthorizations;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Searsandrew\BriarRose\Exceptions\BriarRoseConfigurationException;

class SyncReturnAuthorizationsCommand extends Command
{
    protected $signature = 'milkstool:sync-return-authorizations {customer : NetSuite customer internal ID} {--queue : Queue a background import}';

    protected $description = 'Import and reconcile all RMAs for one customer (read-only in NetSuite)';

    public function handle(SyncReturnAuthorizations $sync): int
    {
        $options = ['options' => ['min_range' => 1]];
        $customer = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, $options);

        if ($customer === false) {
            $this->error('Customer must be a positive NetSuite internal ID.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            RefreshReturnAuthorizations::dispatch($customer);
            $this->info('RMA refresh requested; already queued customers are deduplicated.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->handle($customer, function (int $returnAuthorizations, int $lines): void {
                $this->line("Processed {$returnAuthorizations} RMAs / {$lines} lines.");
            });
        } catch (RequestException $exception) {
            $this->error('NetSuite returned HTTP '.$exception->response->status().'. RMA import did not complete.');

            return self::FAILURE;
        } catch (ConnectionException) {
            $this->error('Could not connect to NetSuite. RMA import did not complete.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error('NetSuite returned invalid RMA data: '.implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (BriarRoseConfigurationException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Sync complete: {$result['returnAuthorizations']} RMAs, {$result['lines']} lines.");

        return self::SUCCESS;
    }
}
