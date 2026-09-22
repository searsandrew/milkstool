<?php

namespace App\Services;

use Closure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ServiceHealth
{
    public const array QUEUES = ['customers', 'balances', 'sales-orders', 'invoices', 'credit-memos', 'payments'];

    /** @return array{healthy: bool, checks: list<array{name: string, healthy: bool}>} */
    public function report(bool $deployment = false): array
    {
        $checks = [
            $this->check('database', fn (): bool => DB::selectOne('SELECT 1 AS available') !== null),
            $this->check('migrations_current', function (): bool {
                $migrator = app('migrator');

                return $migrator->repositoryExists() && array_diff(
                    array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()])),
                    $migrator->getRepository()->getRan(),
                ) === [];
            }),
            $this->check('shared_cache', fn (): bool => in_array(config('cache.stores.'.config('cache.default').'.driver'), ['database', 'redis'], true)),
            $this->check('queue_configuration', fn (): bool => in_array(config('queue.connections.netsuite.driver'), ['database', 'redis'], true)
                && (int) config('queue.connections.netsuite.retry_after') > 1200),
            $this->check('netsuite_credentials_configured', function (): bool {
                foreach (['account', 'consumer_key', 'consumer_secret', 'token_id', 'token_secret'] as $key) {
                    if (! is_string(config('briar-rose.'.$key)) || trim(config('briar-rose.'.$key)) === '') {
                        return false;
                    }
                }

                return true;
            }),
            $this->check('scheduled_sync_enabled', fn (): bool => (bool) config('netsuite-sync.scheduled')),
            $this->check('scheduler_heartbeat', fn (): bool => $this->recent('scheduler', 180)),
        ];
        foreach (self::QUEUES as $queue) {
            $checks[] = $this->check('worker_'.$queue, fn (): bool => $this->recent('worker:'.$queue, 1500));
        }
        if ($deployment) {
            $checks[] = $this->check('production_environment', fn (): bool => app()->environment('production'));
            $checks[] = $this->check('debug_disabled', fn (): bool => ! config('app.debug'));
            $checks[] = $this->check('https_url', fn (): bool => parse_url(config('app.url'), PHP_URL_SCHEME) === 'https');
            $checks[] = $this->check('application_key', fn (): bool => Encrypter::supported(app('encrypter')->getKey(), config('app.cipher')));
        }

        return ['healthy' => collect($checks)->every('healthy'), 'checks' => $checks];
    }

    private function recent(string $component, int $seconds): bool
    {
        $recorded = Cache::get('milkstool:heartbeat:'.$component);

        return is_int($recorded) && $recorded <= now()->timestamp && $recorded >= now()->subSeconds($seconds)->timestamp;
    }

    /** @return array{name: string, healthy: bool} */
    private function check(string $name, Closure $check): array
    {
        try {
            return ['name' => $name, 'healthy' => $check()];
        } catch (Throwable) {
            return ['name' => $name, 'healthy' => false];
        }
    }
}
