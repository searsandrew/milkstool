<?php

use App\Models\Company;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('imports missing active snapshots in bounded source order and skips completed customers on resume', function () {
    $later = Company::factory()->create(['netsuite_id' => 17]);
    $first = Company::factory()->create(['netsuite_id' => 16]);
    Company::factory()->create(['netsuite_id' => 18, 'is_active' => false]);
    Company::factory()->create(['netsuite_id' => 15, 'balance_synced_at' => now()->subDay(), 'account_balance_snapshot' => ['balance' => '0.00000000']]);
    Http::fake([
        'https://netsuite.example/services/rest/record/v1/customer/16*' => Http::response(['id' => '16', 'balance' => 0, 'overdueBalance' => 0, 'unbilledOrders' => 0]),
        'https://netsuite.example/services/rest/record/v1/customer/17*' => Http::response(['id' => '17', 'balance' => 25, 'overdueBalance' => 0, 'unbilledOrders' => 0]),
    ]);

    $this->artisan('milkstool:backfill-balances', ['--limit' => '1'])->assertSuccessful();
    expect($first->refresh()->balance_synced_at)->not->toBeNull();
    expect($first->account_balance_snapshot['balance'])->toBe('0.00000000');
    expect($later->refresh()->balance_synced_at)->toBeNull();
    $this->artisan('milkstool:backfill-balances')->assertSuccessful();
    expect($later->refresh()->account_balance_snapshot['balance'])->toBe('25.00000000');
    $this->artisan('milkstool:backfill-balances')->expectsOutput('Backfill complete: 0 customer balance snapshots imported.')->assertSuccessful();
    Http::assertSentCount(2);
});

it('repairs a missing snapshot even when its success timestamp exists', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'balance_synced_at' => now()]);
    Http::fake(['https://netsuite.example/services/rest/record/v1/customer/16*' => Http::response(['id' => '16', 'balance' => 0, 'overdueBalance' => 0, 'unbilledOrders' => 0])]);

    $this->artisan('milkstool:backfill-balances')->assertSuccessful();

    expect($company->refresh()->account_balance_snapshot['balance'])->toBe('0.00000000');
    Http::assertSentCount(1);
});

it('stops at a failure and resumes from that customer while preserving previously completed snapshots', function () {
    $first = Company::factory()->create(['netsuite_id' => 16]);
    $next = Company::factory()->create(['netsuite_id' => 17]);
    Http::fake(['https://netsuite.example/services/rest/record/v1/customer/16*' => Http::response([], 403)]);

    $this->artisan('milkstool:backfill-balances')->assertFailed();
    expect($first->refresh()->balance_synced_at)->toBeNull();
    expect($next->refresh()->balance_sync_started_at)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/customer/17'));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://netsuite.example/services/rest/record/v1/customer/16*' => Http::response(['id' => '16', 'balance' => 0, 'overdueBalance' => 0, 'unbilledOrders' => 0])]);
    $this->artisan('milkstool:backfill-balances', ['--limit' => '1'])->assertSuccessful();
    expect($first->refresh()->balance_synced_at)->not->toBeNull();
    expect($first->balance_sync_error)->toBeNull();
    Http::assertSentCount(1);
});

it('previews a bounded balance batch without contacting NetSuite or changing data', function () {
    Company::factory()->create(['netsuite_id' => 17]);
    $first = Company::factory()->create(['netsuite_id' => 16]);

    $this->artisan('milkstool:backfill-balances', ['--limit' => '1', '--dry-run' => true])
        ->expectsOutput('Backfill customer 16')->doesntExpectOutput('Backfill customer 17')->assertSuccessful();

    expect($first->refresh()->balance_sync_started_at)->toBeNull();
    Http::assertNothingSent();
});

it('rejects invalid balance batch limits', function (string $limit) {
    $this->artisan('milkstool:backfill-balances', ['--limit' => $limit])->assertFailed();
    Http::assertNothingSent();
})->with(['0', '-1', '1001', 'abc']);

it('refuses concurrent balance backfills', function () {
    $lock = Cache::lock('netsuite-balance-backfill', 86400);
    $lock->get();

    $this->artisan('milkstool:backfill-balances')->assertFailed();

    expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    Http::assertNothingSent();
    $lock->release();
});
