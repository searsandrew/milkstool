<?php

namespace App\Jobs;

use App\Actions\SyncCustomers;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshCustomers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onConnection('netsuite');
        $this->onQueue('customers');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SyncCustomers $sync): void
    {
        try {
            Log::info('NetSuite customer discovery completed.', $sync->handle());
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [408, 429], true) || $exception->response->serverError()) {
                throw $exception;
            }

            $this->fail($exception);
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }
}
