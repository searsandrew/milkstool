<?php

use App\Jobs\RefreshInvoices;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeSecond();
});

it('recovers only a bounded selection of active failed customers and retains evidence', function () {
    Company::factory()->create(['id' => 16, 'is_active' => true, 'invoices_sync_error' => 'Failed', 'invoices_next_sync_at' => now()->addHours(6)]);
    Company::factory()->create(['id' => 17, 'is_active' => false, 'invoices_sync_error' => 'Failed']);
    Company::factory()->create(['id' => 18, 'is_active' => true]);
    Company::factory()->create(['id' => 19, 'is_active' => true, 'invoices_sync_error' => 'Failed']);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'netsuite', 'queue' => 'invoices', 'payload' => '{}', 'exception' => 'DNS failure', 'failed_at' => now()]);
    Queue::fake();

    $this->artisan('milkstool:recover-failed-syncs', ['--limit' => 1])->assertSuccessful();

    Queue::assertPushed(RefreshInvoices::class, fn ($job) => $job->customerId === 16);
    Queue::assertPushed(RefreshInvoices::class, 1);
    expect(Company::findOrFail(16)->invoices_sync_error)->toBe('Failed');
    $this->assertDatabaseCount('failed_jobs', 1);
    Http::assertNothingSent();
});

it('supports dry runs and rejects invalid bounds without queueing', function () {
    Company::factory()->create(['id' => 16, 'is_active' => true, 'invoices_sync_error' => 'Failed']);
    Queue::fake();

    $this->artisan('milkstool:recover-failed-syncs', ['--dry-run' => true])->expectsOutput('Candidate: invoices for customer 16')->assertSuccessful();
    $this->artisan('milkstool:recover-failed-syncs', ['--limit' => 0])->assertFailed();
    $this->artisan('milkstool:recover-failed-syncs', ['--type' => 'unknown'])->assertFailed();
    $this->artisan('milkstool:recover-failed-syncs', ['--customer' => -1])->assertFailed();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('does not enqueue a second copy when recovery is requested twice', function () {
    Company::factory()->create(['id' => 16, 'is_active' => true, 'invoices_sync_error' => 'Failed']);

    $this->artisan('milkstool:recover-failed-syncs')->assertSuccessful();
    $this->artisan('milkstool:recover-failed-syncs')->assertSuccessful();

    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();
});
