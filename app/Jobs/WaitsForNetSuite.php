<?php

namespace App\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Throwable;

trait WaitsForNetSuite
{
    public int $maxExceptions = 3;

    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addHours(6);
    }

    /** @return list<ThrottlesExceptions> */
    public function middleware(): array
    {
        return [(new ThrottlesExceptions(1, 300))
            ->by('milkstool-netsuite')
            ->backoff(function (Throwable $exception): int {
                $seconds = $exception instanceof RequestException ? $exception->response->header('Retry-After') : null;

                return is_string($seconds) && ctype_digit($seconds) ? max(5, (int) ceil((int) $seconds / 60)) : 5;
            })
            ->when(function (Throwable $exception, RateLimiter $limiter): bool {
                $transient = $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && (in_array($exception->response->status(), [408, 429], true) || $exception->response->serverError()));
                if ($transient && $exception instanceof RequestException) {
                    $seconds = $exception->response->header('Retry-After');
                    if (is_string($seconds) && ctype_digit($seconds)) {
                        $limiter->hit('laravel_throttles_exceptions:milkstool-netsuite', max(300, (int) $seconds));
                    }
                }

                return $transient;
            })];
    }
}
