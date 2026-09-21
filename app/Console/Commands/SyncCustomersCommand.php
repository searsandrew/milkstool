<?php

namespace App\Console\Commands;

use App\Actions\SyncCustomers;
use App\Jobs\RefreshCustomers;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Searsandrew\BriarRose\Exceptions\BriarRoseConfigurationException;

class SyncCustomersCommand extends Command
{
    protected $signature = 'milkstool:sync-customers {--dry-run : Preview identity changes without saving customers} {--queue : Queue customer discovery instead of running now}';

    protected $description = 'Discover NetSuite customers and update their identities and active status';

    public function handle(SyncCustomers $sync): int
    {
        if ($this->option('dry-run') && $this->option('queue')) {
            $this->error('Use --dry-run only with foreground discovery.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            RefreshCustomers::dispatch();
            $this->info('Customer discovery requested. Duplicate pending requests are ignored.');

            return self::SUCCESS;
        }

        try {
            $counts = $sync->handle((bool) $this->option('dry-run'));
        } catch (RequestException $exception) {
            $this->error('NetSuite returned HTTP '.$exception->response->status().'. Customer discovery did not complete.');

            return self::FAILURE;
        } catch (ConnectionException) {
            $this->error('Could not connect to NetSuite. Customer discovery did not complete.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error('NetSuite returned invalid customer data: '.implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (BriarRoseConfigurationException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Result', 'Customers'], collect($counts)->map(fn (int $count, string $result): array => [$result, $count])->values()->all());
        $this->info($this->option('dry-run') ? 'Preview complete. No customers changed or jobs queued.' : 'Discovery complete. Due active customers are eligible for the sales-order dispatcher.');

        return self::SUCCESS;
    }
}
