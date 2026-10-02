<?php

namespace App\Jobs;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class NetSuiteCooldown
{
    public function handle(object $job, Closure $next): void
    {
        $limiter = app(RateLimiter::class);
        $key = 'laravel_throttles_exceptions:milkstool-netsuite';
        if ($limiter->tooManyAttempts($key, 1)) {
            $job->release($limiter->availableIn($key) + 3);

            return;
        }

        try {
            $next($job);
        } catch (Throwable $exception) {
            $transient = $exception instanceof ConnectionException
                || ($exception instanceof RequestException && (in_array($exception->response->status(), [408, 429], true) || $exception->response->serverError()));
            if ($transient) {
                $retryAfter = $exception instanceof RequestException ? $exception->response->header('Retry-After') : null;
                $limiter->hit($key, is_string($retryAfter) && ctype_digit($retryAfter) ? max(300, (int) $retryAfter) : 300);
                if ($exception instanceof RequestException && $exception->response->status() === 429) {
                    $job->release($limiter->availableIn($key) + 3);

                    return;
                }
            }

            throw $exception;
        }
    }
}
