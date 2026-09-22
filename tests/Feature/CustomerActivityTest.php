<?php

use App\Jobs\RefreshCreditMemos;
use App\Jobs\RefreshCustomerBalance;
use App\Jobs\RefreshInvoices;
use App\Jobs\RefreshPayments;
use App\Jobs\RefreshSalesOrders;
use App\Models\ApiClient;
use App\Models\Company;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeSecond();
    $this->company = Company::factory()->create(['netsuite_id' => 16]);
});

it('requires a customer grant and explicit activity permission', function () {
    $this->postJson('/api/v1/customers/16/activity')->assertUnauthorized();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->postJson('/api/v1/customers/16/activity')->assertForbidden();
    Sanctum::actingAs(ApiClient::factory()->create(), ['activity:write', 'customer:17']);
    $this->postJson('/api/v1/customers/16/activity')->assertForbidden();
    expect($this->company->refresh()->portal_last_active_at)->toBeNull();
    $this->assertDatabaseCount('jobs', 0);
});

it('records a visit and queues each missing history once without contacting NetSuite', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['activity:write', 'customer:16']);
    $this->postJson('/api/v1/customers/16/activity')->assertAccepted()
        ->assertJsonPath('data.refreshes_requested', ['balance', 'sales_orders', 'invoices', 'credit_memos', 'payments'])
        ->assertJsonPath('data.active_until', now()->addDay()->toIso8601String());
    $this->postJson('/api/v1/customers/16/activity')->assertAccepted();
    $this->assertDatabaseCount('jobs', 5);
    expect($this->company->refresh()->portal_last_active_at->eq(now()))->toBeTrue();
    Http::assertNothingSent();
});

it('does not queue fresh data and preserves the failure cooldown', function () {
    Queue::fake();
    Sanctum::actingAs(ApiClient::factory()->create(), ['activity:write', 'customer:16']);
    foreach (['balance', 'sales_orders', 'invoices', 'credit_memos', 'payments'] as $prefix) {
        $this->company->forceFill([$prefix.'_synced_at' => now()->subMinutes(5), $prefix.'_next_sync_at' => now()->addHours(6)])->save();
    }
    $this->company->forceFill(['invoices_synced_at' => now()->subDay(), 'invoices_sync_error' => 'Retry later'])->save();
    $this->postJson('/api/v1/customers/16/activity')->assertAccepted()->assertJsonPath('data.refreshes_requested', []);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('starts a refresh when a returning visitor has data older than fifteen minutes', function () {
    Queue::fake();
    Sanctum::actingAs(ApiClient::factory()->create(), ['activity:write', 'customer:16']);
    foreach (['balance', 'sales_orders', 'invoices', 'credit_memos', 'payments'] as $prefix) {
        $this->company->forceFill([$prefix.'_synced_at' => now()->subMinutes(16), $prefix.'_next_sync_at' => now()->addHours(5)])->save();
    }
    $this->postJson('/api/v1/customers/16/activity')->assertAccepted();
    foreach ([RefreshCustomerBalance::class, RefreshSalesOrders::class, RefreshInvoices::class, RefreshCreditMemos::class, RefreshPayments::class] as $job) {
        Queue::assertPushed($job, 1);
    }
    Http::assertNothingSent();
});

it('keeps recent visitors due while excluding expired activity and failed cooldowns', function (string $command, string $prefix, string $job) {
    Queue::fake();
    $state = [$prefix.'_synced_at' => now()->subMinutes(16), $prefix.'_next_sync_at' => now()->addHours(5), 'portal_last_active_at' => now()->subHours(23)];
    $this->company->forceFill($state)->save();
    Company::factory()->create([...$state, 'portal_last_active_at' => now()->subHours(25)]);
    Company::factory()->create([...$state, $prefix.'_sync_error' => 'Cooling down']);
    Company::factory()->create([...$state, 'is_active' => false]);

    $this->artisan($command)->assertSuccessful();
    Queue::assertPushed($job, 1);
    Queue::assertPushed($job, fn ($queued) => $queued->customerId === 16);
    Http::assertNothingSent();
})->with([
    ['milkstool:dispatch-sales-order-refreshes', 'sales_orders', RefreshSalesOrders::class],
    ['milkstool:dispatch-invoice-refreshes', 'invoices', RefreshInvoices::class],
    ['milkstool:dispatch-credit-memo-refreshes', 'credit_memos', RefreshCreditMemos::class],
    ['milkstool:dispatch-balance-refreshes', 'balance', RefreshCustomerBalance::class],
    ['milkstool:dispatch-payment-refreshes', 'payments', RefreshPayments::class],
]);

it('sets the next successful balance refresh according to recent activity', function (int $hoursAgo, int $minutesUntilRefresh) {
    $this->company->forceFill(['portal_last_active_at' => now()->subHours($hoursAgo)])->save();
    Http::fake(['https://netsuite.example/*' => Http::response(['id' => '16', 'balance' => 0, 'overdueBalance' => 0, 'unbilledOrders' => 0])]);
    $this->artisan('milkstool:sync-balance', ['customer' => 16])->assertSuccessful();
    expect($this->company->refresh()->balance_next_sync_at->eq(now()->addMinutes($minutesUntilRefresh)))->toBeTrue();
})->with([[23, 15], [25, 360]]);

it('shows the faster due time in API freshness and command status', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->company->forceFill(['portal_last_active_at' => now(), 'invoices_synced_at' => now()->subMinutes(16),
        'invoices_backfilled_at' => now()->subDay(), 'invoices_next_sync_at' => now()->addHours(5)])->save();
    $this->getJson('/api/v1/customers/16/invoice-summary')->assertJsonPath('sync.invoices.status', 'stale');
    Artisan::call('milkstool:sync-status', ['--type' => 'invoices', '--customer' => 16, '--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['customers'][0]['status'])->toBe('due');
    expect($report['customers'][0]['next_sync_at'])->toBe(now()->subMinute()->toIso8601String());
});

it('does not reactivate an inactive customer through activity reporting', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['activity:write', 'customer:16']);
    $this->company->forceFill(['is_active' => false])->save();
    $this->postJson('/api/v1/customers/16/activity')->assertStatus(409);
    expect($this->company->refresh()->portal_last_active_at)->toBeNull();
    $this->assertDatabaseCount('jobs', 0);
});

it('issues activity permissions only with an explicit request and customer scope', function () {
    $this->artisan('milkstool:token:issue', ['client' => 'portal', '--activity' => true])->assertFailed();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Artisan::call('milkstool:token:issue', ['client' => 'portal', '--activity' => true, '--customer' => ['16']]);
    expect(ApiClient::query()->where('name', 'portal')->firstOrFail()->tokens()->sole()->abilities)
        ->toContain('activity:write', 'customer:16');
});
