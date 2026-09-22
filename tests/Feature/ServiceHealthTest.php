<?php

use App\Models\ApiClient;
use App\Services\ServiceHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeSecond();
    config()->set('cache.default', 'database');
    config()->set('netsuite-sync.scheduled', true);
});

function processHealthProbes(): void
{
    expect(Artisan::call('milkstool:heartbeat'))->toBe(0);
    foreach (ServiceHealth::QUEUES as $queue) {
        Queue::connection('netsuite')->pop($queue)->fire();
    }
}

it('proves each queue is processing and deduplicates probes while workers are stopped', function () {
    $this->artisan('milkstool:heartbeat')->assertSuccessful();
    $this->artisan('milkstool:heartbeat')->assertSuccessful();
    $this->assertDatabaseCount('jobs', 6);
    expect(app(ServiceHealth::class)->report()['healthy'])->toBeFalse();
    foreach (ServiceHealth::QUEUES as $queue) {
        Queue::connection('netsuite')->pop($queue)->fire();
    }

    $this->artisan('milkstool:health')->assertSuccessful();

    $this->assertDatabaseCount('jobs', 0);
    Http::assertNothingSent();
});

it('detects a stopped scheduler and individual unprocessed queues without exposing secrets', function () {
    processHealthProbes();
    Cache::forget('milkstool:heartbeat:worker:payments');
    $this->travel(181)->seconds();

    expect(Artisan::call('milkstool:health', ['--json' => true]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($report['checks'])->where('healthy', false)->pluck('name')->all())->toBe(['scheduler_heartbeat', 'worker_payments']);
    expect(Artisan::output())->not->toContain('testing', 'consumer_secret', 'token_secret');
    Http::assertNothingSent();
});

it('allows a running long import but fails old or future worker heartbeats', function (int $age, bool $healthy) {
    processHealthProbes();
    Cache::put('milkstool:heartbeat:worker:invoices', now()->subSeconds($age)->timestamp, 3600);

    expect(app(ServiceHealth::class)->report()['healthy'])->toBe($healthy);
})->with([[1200, true], [1500, true], [1501, false], [-1, false]]);

it('fails incomplete or unsafe runtime configuration', function (string $key, mixed $value, string $check) {
    processHealthProbes();
    config()->set($key, $value);

    $checks = collect(app(ServiceHealth::class)->report()['checks'])->keyBy('name');

    expect($checks[$check]['healthy'])->toBeFalse();
})->with([
    ['queue.connections.netsuite.driver', 'sync', 'queue_configuration'],
    ['queue.connections.netsuite.retry_after', 1200, 'queue_configuration'],
    ['cache.default', 'array', 'shared_cache'],
    ['netsuite-sync.scheduled', false, 'scheduled_sync_enabled'],
    ['briar-rose.token_secret', '', 'netsuite_credentials_configured'],
]);

it('does not fabricate worker health with a synchronous queue', function () {
    config()->set('queue.connections.netsuite.driver', 'sync');

    $this->artisan('milkstool:heartbeat')->assertFailed();

    expect(Cache::get('milkstool:heartbeat:scheduler'))->toBeNull();
    $this->assertDatabaseCount('jobs', 0);
});

it('fails when migrations are pending', function () {
    processHealthProbes();
    DB::table('migrations')->where('migration', DB::table('migrations')->value('migration'))->delete();

    $checks = collect(app(ServiceHealth::class)->report()['checks'])->keyBy('name');

    expect($checks['migrations_current']['healthy'])->toBeFalse();
});

it('checks production settings only when the deployment option is requested', function () {
    processHealthProbes();
    config()->set('app.debug', true);
    config()->set('app.url', 'http://localhost');
    $this->artisan('milkstool:health')->assertSuccessful();

    expect(Artisan::call('milkstool:health', ['--deployment' => true, '--json' => true]))->toBe(1);
    $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('name');

    expect($checks['production_environment']['healthy'])->toBeFalse();
    expect($checks['debug_disabled']['healthy'])->toBeFalse();
    expect($checks['https_url']['healthy'])->toBeFalse();
});

it('protects API health checks and reports degradation as 503 without NetSuite requests', function () {
    $this->getJson('/api/v1/health')->assertUnauthorized();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read']);
    $this->getJson('/api/v1/health')->assertForbidden();
    Sanctum::actingAs(ApiClient::factory()->create(), ['status:read']);
    $this->getJson('/api/v1/health')->assertServiceUnavailable()->assertJsonPath('data.healthy', false);
    processHealthProbes();

    $response = $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.healthy', true);

    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    Http::assertNothingSent();
});

it('schedules heartbeats every minute only when scheduled sync is enabled', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'milkstool:heartbeat'));
    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('* * * * *');
    expect($event->filtersPass(app()))->toBeTrue();
    config()->set('netsuite-sync.scheduled', false);
    expect($event->filtersPass(app()))->toBeFalse();
});
