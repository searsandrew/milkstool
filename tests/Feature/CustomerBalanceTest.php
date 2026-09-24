<?php

use App\Actions\SyncCustomerBalance;
use App\Exceptions\ReceivableSyncInterrupted;
use App\Jobs\RefreshCustomerBalance;
use App\Models\ApiClient;
use App\Models\Company;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->company = Company::factory()->create(['id' => 16]);
});

/** @return array<string, mixed> */
function balanceRecord(): array
{
    return ['id' => '16', 'balance' => '123.12345678', 'overdueBalance' => 0, 'unbilledOrders' => 25.5,
        'consolBalance' => '900', 'depositBalance' => '-10', 'currency' => ['id' => '1'], 'subsidiary' => ['id' => '1']];
}

it('mirrors balances and consolidated values without deriving them from invoices', function () {
    Http::fake(['https://netsuite.example/services/rest/record/v1/customer/16*' => Http::response(balanceRecord())]);
    $this->artisan('milkstool:sync-balance', ['customer' => '16'])->assertSuccessful();
    expect($this->company->refresh()->account_balance_snapshot)->toMatchArray([
        'balance' => '123.12345678', 'overdue_balance' => '0.00000000', 'unbilled_orders' => '25.50000000',
        'consolidated_balance' => '900.00000000', 'deposit_balance' => '-10.00000000',
        'consolidated_deposit_balance' => null, 'currency_id' => 1, 'subsidiary_id' => 1,
    ]);
    expect($this->company->balance_synced_at)->not->toBeNull();
    expect($this->company->invoices_synced_at)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

it('preserves unknown currency and clears optional values removed by NetSuite', function () {
    $this->company->forceFill(['account_balance_snapshot' => ['deposit_balance' => '99', 'currency_id' => 1]])->save();
    Http::fake(['https://netsuite.example/*' => Http::response(['id' => '16', 'balance' => 0, 'overdueBalance' => 0, 'unbilledOrders' => 0])]);
    app(SyncCustomerBalance::class)->handle(16);
    expect($this->company->refresh()->account_balance_snapshot)->toMatchArray(['balance' => '0.00000000', 'deposit_balance' => null, 'currency_id' => null]);
});

it('retains the last snapshot when the response is invalid or unavailable', function (array $record, int $status) {
    $this->company->forceFill(['account_balance_snapshot' => ['balance' => '50.00000000'], 'balance_synced_at' => '2026-09-01 12:00:00'])->save();
    Http::fake(['https://netsuite.example/*' => Http::response($record, $status)]);
    $this->artisan('milkstool:sync-balance', ['customer' => '16'])->assertFailed();
    expect($this->company->refresh()->account_balance_snapshot)->toBe(['balance' => '50.00000000']);
    expect($this->company->balance_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($this->company->balance_sync_error)->not->toBeNull();
    expect(Cache::lock('netsuite-balance:16', 600)->get())->toBeTrue();
})->with([
    'permission' => [[], 403], 'unavailable' => [[], 503],
    'missing balance' => [['id' => '16'], 200],
    'wrong customer' => [array_replace(balanceRecord(), ['id' => '17']), 200],
    'invalid amount' => [array_replace(balanceRecord(), ['balance' => 'invalid']), 200],
    'excess precision' => [array_replace(balanceRecord(), ['balance' => '1.123456789']), 200],
]);

it('does not contact NetSuite for unregistered customers or busy refreshes', function () {
    $this->artisan('milkstool:sync-balance', ['customer' => 999])->assertFailed();
    Cache::lock('netsuite-balance:16', 600)->get();
    $this->artisan('milkstool:sync-balance', ['customer' => 16])->assertFailed();
    expect($this->company->refresh()->balance_sync_started_at)->toBeNull();
    Http::assertNothingSent();
});

it('queues and executes a unique refresh without doing network work in the command', function () {
    $this->artisan('milkstool:sync-balance', ['customer' => 16, '--queue' => true])->assertSuccessful();
    $this->artisan('milkstool:sync-balance', ['customer' => 16, '--queue' => true])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();
    Http::fake(['https://netsuite.example/*' => Http::response(balanceRecord())]);
    Queue::connection('netsuite')->pop('balances')->fire();
    expect($this->company->refresh()->balance_synced_at)->not->toBeNull();
    $this->assertDatabaseCount('jobs', 0);
    $this->artisan('milkstool:dispatch-balance-refreshes', ['--dry-run' => true])->expectsOutput('0 customers due. No jobs queued.')->assertSuccessful();
});

it('dispatches only due active balances and keeps dry runs read only', function () {
    Queue::fake();
    Company::factory()->create(['is_active' => false]);
    Company::factory()->create(['balance_next_sync_at' => now()->addHour()]);
    $this->artisan('milkstool:dispatch-balance-refreshes', ['--dry-run' => true])->assertSuccessful();
    Queue::assertNothingPushed();
    $this->artisan('milkstool:dispatch-balance-refreshes')->assertSuccessful();
    Queue::assertPushed(RefreshCustomerBalance::class, 1);
    Queue::assertPushed(RefreshCustomerBalance::class, fn ($job) => $job->customerId === 16);
    Http::assertNothingSent();
});

it('retries transient errors but fails forbidden requests', function (int $status) {
    Http::fake(['https://netsuite.example/*' => Http::response([], $status)]);
    $job = (new RefreshCustomerBalance(16))->withFakeQueueInteractions();
    if ($status === 403) {
        $job->handle(app(SyncCustomerBalance::class));
        $job->assertFailedWith(RequestException::class);
    } else {
        expect(fn () => $job->handle(app(SyncCustomerBalance::class)))->toThrow(RequestException::class);
        $job->assertNotFailed();
    }
})->with([403, 429, 503]);

it('allows lock contention to retry and protects newer successful snapshots from failed old jobs', function () {
    $job = (new RefreshCustomerBalance(16))->withFakeQueueInteractions();
    Cache::lock('netsuite-balance:16', 600)->get();
    expect(fn () => $job->handle(app(SyncCustomerBalance::class)))->toThrow(ReceivableSyncInterrupted::class);
    $this->company->forceFill(['balance_synced_at' => now()->addMinute()])->save();
    $job->failed(new RuntimeException('Old job'));
    expect($this->company->refresh()->balance_sync_error)->toBeNull();
    Http::assertNothingSent();
});

it('requires authentication and customer permission for the balance endpoint', function () {
    $this->getJson('/api/v1/customers/16/balance')->assertUnauthorized();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:17']);
    $this->getJson('/api/v1/customers/16/balance')->assertForbidden();
    Sanctum::actingAs(ApiClient::factory()->create(), ['customer:16']);
    $this->getJson('/api/v1/customers/16/balance')->assertForbidden();
});

it('serves local balance snapshots and their independent freshness without contacting NetSuite', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/balance')->assertOk()->assertJsonPath('data', null)->assertJsonPath('sync.status', 'never_synced');
    $this->company->forceFill(['account_balance_snapshot' => ['balance' => '-50.00000000', 'currency_id' => null],
        'balance_synced_at' => now(), 'balance_next_sync_at' => now()->addHours(6)])->save();
    $this->getJson('/api/v1/customers/16/balance')->assertOk()->assertJsonPath('data.balance', '-50.00000000')
        ->assertJsonPath('sync.status', 'current')->assertJsonPath('source', 'netsuite_customer_record');
    $this->company->forceFill(['balance_sync_error' => 'private error'])->save();
    $this->getJson('/api/v1/customers/16/balance')->assertJsonPath('data.balance', '-50.00000000')
        ->assertJsonPath('sync.status', 'failed')->assertDontSee('private error');
    Http::assertNothingSent();
});

it('keeps automatic balance refreshes behind the scheduler switch', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'milkstool:dispatch-balance-refreshes'));
    config()->set('netsuite-sync.scheduled', false);
    expect($event->filtersPass(app()))->toBeFalse();
    config()->set('netsuite-sync.scheduled', true);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('shows stale or interrupted balance snapshots without suppressing the last value', function (string $status) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->company->forceFill(['account_balance_snapshot' => ['balance' => '1.00000000'],
        'balance_synced_at' => now()->subDay(), 'balance_next_sync_at' => now()->subHour(),
        'balance_sync_started_at' => $status === 'unfinished_attempt' ? now() : null])->save();
    $this->getJson('/api/v1/customers/16/balance')->assertOk()->assertJsonPath('sync.status', $status)->assertJsonPath('data.balance', '1.00000000');
})->with(['stale', 'unfinished_attempt']);

it('skips inactive customers when queued and rejects invalid customer IDs in the command', function () {
    $this->company->forceFill(['is_active' => false])->save();
    (new RefreshCustomerBalance(16))->handle(app(SyncCustomerBalance::class));
    $this->artisan('milkstool:sync-balance', ['customer' => '0'])->assertFailed();
    Http::assertNothingSent();
});
