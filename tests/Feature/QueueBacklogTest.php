<?php

use App\Jobs\RefreshInvoices;
use App\Models\Company;
use App\Services\ServiceHealth;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->travelTo('2026-09-16 12:00:00');
});

it('executes an invoice job after waiting more than six hours', function () {
    $company = Company::factory()->create(['id' => 16, 'is_active' => true]);
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([])))]);
    RefreshInvoices::dispatch(16);
    $this->travel(2)->days();

    $this->artisan('queue:work', ['connection' => 'netsuite', '--queue' => 'invoices', '--once' => true, '--sleep' => 0])->assertSuccessful();

    expect($company->refresh()->invoices_backfilled_at)->not->toBeNull();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    Http::assertSentCount(5);
});

it('does not spend exception retries while repeatedly waiting for an overlap lock or cooldown', function (bool $busy) {
    $company = Company::factory()->create(['id' => 16, 'is_active' => true]);
    $lock = Cache::lock('laravel-queue-overlap:invoice-work:16', 3600);
    if ($busy) {
        $lock->get();
    } else {
        app(RateLimiter::class)->hit('laravel_throttles_exceptions:milkstool-netsuite', 3600);
    }
    RefreshInvoices::dispatch(16);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->artisan('queue:work', ['connection' => 'netsuite', '--queue' => 'invoices', '--once' => true, '--sleep' => 0])->assertSuccessful();
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
    }

    expect(DB::table('jobs')->sole()->attempts)->toBe(5);
    expect($company->refresh()->invoices_sync_started_at)->toBeNull();
    $this->assertDatabaseCount('failed_jobs', 0);
    Http::assertNothingSent();
    $lock->release();
})->with([true, false]);

it('keeps routine invoices separate from history and deduplicates repeated scheduler runs', function () {
    config()->set('netsuite-sync.invoice_history_queue', 'invoice-history');
    Company::factory()->create(['id' => 16, 'is_active' => true]);
    Company::factory()->create(['id' => 17, 'is_active' => true, 'invoices_backfilled_at' => now()->subDay()]);

    $this->artisan('milkstool:dispatch-invoice-refreshes')->assertSuccessful();
    $this->artisan('milkstool:dispatch-invoice-refreshes')->assertSuccessful();

    $this->assertDatabaseCount('jobs', 2);
    expect(DB::table('jobs')->where('queue', 'invoice-history')->count())->toBe(1);
    expect(DB::table('jobs')->where('queue', 'invoices')->count())->toBe(1);
    $this->artisan('milkstool:heartbeat')->assertSuccessful();
    expect(DB::table('jobs')->where('queue', 'invoice-history')->count())->toBe(2);
    expect(ServiceHealth::queues())->toContain('invoice-history');
    Http::assertNothingSent();
});

it('honors repeated NetSuite rate limits without consuming the execution failure budget', function () {
    Company::factory()->create(['id' => 16, 'is_active' => true]);
    Http::fake(['https://netsuite.example/*' => Http::response([], 429, ['Retry-After' => '900'])]);
    RefreshInvoices::dispatch(16);

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $this->artisan('queue:work', ['connection' => 'netsuite', '--queue' => 'invoices', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->travel(904)->seconds();
    }

    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseCount('failed_jobs', 0);
    Http::assertSentCount(4);
});
