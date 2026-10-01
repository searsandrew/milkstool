<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Transaction;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ServiceHealth
{
    public const array QUEUES = ['customers', 'balances', 'sales-orders', 'invoices', 'credit-memos', 'payments'];

    /** @return array<string, mixed> */
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

        $runtimeHealthy = collect($checks)->every('healthy');
        $data = null;
        $checks[] = $this->check('sync_data', function () use (&$data): bool {
            $data = $this->dataHealth();

            return $data['healthy'];
        });
        $checks[] = $this->check('netsuite_outage_cooldown', fn (): bool => ! app(RateLimiter::class)->tooManyAttempts('laravel_throttles_exceptions:milkstool-netsuite', 1));

        return ['healthy' => collect($checks)->every('healthy'), 'runtime_healthy' => $runtimeHealthy, 'checks' => $checks, 'data' => $data];
    }

    /** @return array<string, mixed> */
    private function dataHealth(): array
    {
        $companies = Company::query()->where('is_active', true)->get();
        $types = [];
        foreach (['sales_orders', 'invoices', 'credit_memos', 'balance', 'payments'] as $prefix) {
            $types[$prefix] = ['failed' => 0, 'never_synced' => 0, 'overdue' => 0, 'unfinished' => 0];
            foreach ($companies as $company) {
                $success = $company->{$prefix.'_synced_at'};
                $started = $company->{$prefix.'_sync_started_at'};
                $types[$prefix]['failed'] += (int) ($company->{$prefix.'_sync_error'} !== null);
                $types[$prefix]['never_synced'] += (int) ($success === null);
                $freshnessMinutes = $company->portal_last_active_at?->gte(now()->subDay()) ? 15 : 360;
                $types[$prefix]['overdue'] += (int) ($success !== null && $success->lt(now()->subMinutes($freshnessMinutes + 30)));
                $types[$prefix]['unfinished'] += (int) ($started !== null && ($success === null || $started->gt($success)) && $started->lt(now()->subMinutes(25)));
            }
        }
        $enrichment = app(InvoiceEnrichmentStatus::class)->report();
        unset($enrichment['customers']);
        $failedJobs = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'))
            ->where('connection', 'netsuite')->count();
        $invoices = Transaction::query()->where('type', 'CustInvc')->whereHas('company', fn ($query) => $query->where('is_active', true));
        $missingSummary = (clone $invoices)->whereNull('invoice_details->summary')->count();

        return [
            'healthy' => collect($types)->every(fn (array $counts): bool => array_sum($counts) === 0) && $enrichment['complete'],
            'customers' => $companies->count(),
            'types' => $types,
            'retained_failed_jobs' => $failedJobs,
            'freshness_grace_minutes' => 30,
            'enrichment' => [...$enrichment,
                'invoices' => $invoices->count(),
                'missing_stored_summary' => $missingSummary,
                'pending' => $enrichment['summary_pending'],
                'status' => 'tracked_separately_from_financial_sync',
            ],
        ];
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
