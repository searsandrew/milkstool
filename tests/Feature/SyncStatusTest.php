<?php

use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

/** @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function syncStatusReport(array $options = []): array
{
    expect(Artisan::call('milkstool:sync-status', ['--json' => true, ...$options]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('reports current, due, failed, never-synced and unfinished customers without mutating or contacting NetSuite', function () {
    $this->freezeSecond();
    $current = Company::factory()->create(['netsuite_id' => 16, 'sales_orders_synced_at' => now()->subHour(),
        'sales_orders_next_sync_at' => now()->addHours(5), 'sales_orders_backfilled_at' => now()->subDay(), 'raw_payload' => ['private' => 'excluded']]);
    Company::factory()->create(['netsuite_id' => 17, 'sales_orders_synced_at' => now()->subHours(6)]);
    Company::factory()->create(['netsuite_id' => 18, 'sales_orders_sync_error' => 'Import failed.']);
    Company::factory()->create(['netsuite_id' => 19]);
    Company::factory()->create(['netsuite_id' => 20, 'sales_orders_sync_started_at' => now()->subMinutes(2), 'sales_orders_synced_at' => now()->subHour()]);
    Company::factory()->create(['netsuite_id' => 21, 'is_active' => false]);
    Transaction::factory()->for($current)->create();
    Transaction::factory()->for($current)->create(['type' => 'CustInvc']);
    $before = $current->refresh()->getAttributes();

    $report = syncStatusReport();

    expect(array_column($report['customers'], 'status', 'netsuite_id'))->toBe([
        16 => 'current', 17 => 'due', 18 => 'failed', 19 => 'never_synced', 20 => 'unfinished_attempt',
    ]);
    expect($report['summary'])->toMatchArray(['customers' => 5, 'backfilled' => 1, 'needs_attention' => 4, 'sales_orders' => 1]);
    expect($report['customers'][0])->not->toHaveKey('raw_payload');
    expect($current->refresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('jobs', 0);
    Http::assertNothingSent();
});

it('includes pending backfills in attention even when the latest sync is current', function () {
    $this->freezeSecond();
    Company::factory()->create(['netsuite_id' => 16, 'sales_orders_synced_at' => now(), 'sales_orders_next_sync_at' => now()->addHours(6)]);
    Company::factory()->create(['netsuite_id' => 17, 'sales_orders_synced_at' => now(), 'sales_orders_next_sync_at' => now()->addHours(6), 'sales_orders_backfilled_at' => now()]);
    Company::factory()->create(['netsuite_id' => 18, 'is_active' => false, 'sales_orders_sync_error' => 'Old error']);

    $report = syncStatusReport(['--attention' => true, '--include-inactive' => true]);

    expect(array_column($report['customers'], 'netsuite_id'))->toBe([16]);
    expect($report['customers'][0]['status'])->toBe('current');
    expect($report['summary']['needs_attention'])->toBe(1);
});

it('shows an inactive customer when explicitly requested and includes timestamp details in the table view', function () {
    Company::factory()->create(['netsuite_id' => 16, 'is_active' => false,
        'sales_orders_checkpoint_at' => '2026-09-21 12:00:00', 'sales_orders_backfilled_at' => '2026-09-21 12:02:00']);

    $report = syncStatusReport(['--customer' => '16']);

    expect($report['customers'][0]['status'])->toBe('inactive');
    expect($report['customers'][0]['needs_attention'])->toBeFalse();
    $this->artisan('milkstool:sync-status', ['--customer' => '16'])
        ->expectsOutput('Source checkpoint (UTC): 2026-09-21T12:00:00+00:00')
        ->expectsOutput('Backfill reconciled (UTC): 2026-09-21T12:02:00+00:00')->assertSuccessful();
});

it('honors a due time earlier than the normal baseline and does not mark equal start and success times unfinished', function () {
    $this->freezeSecond();
    Company::factory()->create(['netsuite_id' => 16, 'sales_orders_sync_started_at' => now(),
        'sales_orders_synced_at' => now(), 'sales_orders_next_sync_at' => now()]);

    $report = syncStatusReport();

    expect($report['customers'][0]['status'])->toBe('due');
});

it('reports global queue states and scopes failed jobs without exposing their payloads', function () {
    $this->freezeSecond();
    $now = now()->timestamp;
    foreach ([
        ['customers', null, $now],
        ['sales-orders', null, $now],
        ['sales-orders', null, $now + 60],
        ['sales-orders', $now, $now],
        ['sales-orders', $now - 1260, $now],
        ['unrelated', null, $now],
        ['invoices', null, $now],
        ['credit-memos', null, $now + 60],
    ] as [$queue, $reserved, $available]) {
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => 'private payload', 'attempts' => 0,
            'reserved_at' => $reserved, 'available_at' => $available, 'created_at' => $now]);
    }
    foreach (['netsuite', 'database'] as $connection) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => $connection,
            'queue' => 'sales-orders', 'payload' => 'private payload', 'exception' => 'private exception', 'failed_at' => now()]);
    }

    $report = syncStatusReport();

    expect($report['queues'])->toBe([
        ['name' => 'customers', 'ready' => 1, 'delayed' => 0, 'reserved' => 0, 'expired_reservations' => 0, 'failed' => 0],
        ['name' => 'sales-orders', 'ready' => 1, 'delayed' => 1, 'reserved' => 2, 'expired_reservations' => 1, 'failed' => 1],
        ['name' => 'invoices', 'ready' => 1, 'delayed' => 0, 'reserved' => 0, 'expired_reservations' => 0, 'failed' => 0],
        ['name' => 'credit-memos', 'ready' => 0, 'delayed' => 1, 'reserved' => 0, 'expired_reservations' => 0, 'failed' => 0],
    ]);
    expect(Artisan::output())->not->toContain('private payload', 'private exception');
    $this->assertDatabaseCount('jobs', 8);
    $this->assertDatabaseCount('failed_jobs', 2);
    Http::assertNothingSent();
});

it('reports unavailable queue metrics instead of zero for unsupported storage', function () {
    config()->set('queue.connections.netsuite.driver', 'redis');
    config()->set('queue.failed.driver', 'null');

    $report = syncStatusReport();

    expect($report['queues'][0])->toBe(['name' => 'customers', 'ready' => null, 'delayed' => null, 'reserved' => null, 'expired_reservations' => null, 'failed' => null]);
});

it('reports the scheduler configuration and a valid empty report', function (bool $enabled) {
    config()->set('netsuite-sync.scheduled', $enabled);

    $report = syncStatusReport();

    expect($report['scheduled_sync_enabled'])->toBe($enabled);
    expect($report['customers'])->toBe([]);
    expect($report['summary'])->toMatchArray(['customers' => 0, 'backfilled' => 0, 'needs_attention' => 0, 'sales_orders' => 0]);
})->with([true, false]);

it('rejects invalid or unregistered customer IDs', function (string $id) {
    $this->artisan('milkstool:sync-status', ['--customer' => $id])->assertFailed();
    Http::assertNothingSent();
})->with(['0', '-1', '16 OR 1=1', '999999']);

it('reports billing freshness independently from sales orders', function (string $type, string $prefix, string $sourceType, string $countKey) {
    $company = Company::factory()->create(['netsuite_id' => 16, $prefix.'_synced_at' => now(),
        $prefix.'_backfilled_at' => now(), $prefix.'_next_sync_at' => now()->addHours(6)]);
    Transaction::factory()->for($company)->create(['type' => $sourceType]);
    Transaction::factory()->for($company)->create(['type' => 'SalesOrd']);

    $report = syncStatusReport(['--type' => $type]);

    expect($report['type'])->toBe($type);
    expect($report['summary'][$prefix])->toBe(1);
    expect($report['customers'][0]['last_full_sync_at'])->toBe($report['customers'][0]['last_success_at']);
    expect($report['customers'][0])->toMatchArray(['status' => 'current', 'needs_attention' => false, $countKey => 1]);
    $this->artisan('milkstool:sync-status', ['--type' => $type, '--customer' => 16])->assertSuccessful();
    $company->forceFill([$prefix.'_sync_error' => 'Failed'])->save();
    expect(syncStatusReport(['--type' => $type, '--attention' => true])['customers'][0]['status'])->toBe('failed');
    Http::assertNothingSent();
})->with([
    ['invoices', 'invoices', 'CustInvc', 'invoice_count'],
    ['credit-memos', 'credit_memos', 'CustCred', 'credit_memo_count'],
]);

it('rejects unsupported status types', function () {
    $this->artisan('milkstool:sync-status', ['--type' => 'payments'])->assertFailed();
    Http::assertNothingSent();
});
