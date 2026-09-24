<?php

use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('marks a reconciled customer complete and skips it on subsequent runs', function () {
    $company = Company::factory()->create(['id' => 16]);
    Company::factory()->create(['id' => 17, 'is_active' => false]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([])))]);

    $this->artisan('milkstool:backfill-credit-memos', ['--limit' => '1'])->assertSuccessful();
    expect($company->refresh()->credit_memos_backfilled_at)->not->toBeNull();
    $this->artisan('milkstool:backfill-credit-memos')->expectsOutput('Backfill complete: 0 customers imported and reconciled.')->assertSuccessful();
    Http::assertSentCount(5);
});

it('stops before the next customer when import fails and leaves the failed customer resumable', function () {
    $company = Company::factory()->create(['id' => 16]);
    $next = Company::factory()->create(['id' => 17]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], 403)]);

    $this->artisan('milkstool:backfill-credit-memos')->assertFailed();

    expect($company->refresh()->credit_memos_backfilled_at)->toBeNull();
    expect($next->refresh()->credit_memos_synced_at)->toBeNull();
    Http::assertSentCount(1);
    expect(Cache::lock('netsuite-credit-memo-backfill', 86400)->get())->toBeTrue();
});

it('previews only the bounded batch in source ID order without contacting NetSuite', function () {
    Company::factory()->create(['id' => 17]);
    Company::factory()->create(['id' => 16]);

    $this->artisan('milkstool:backfill-credit-memos', ['--limit' => '1', '--dry-run' => true])
        ->expectsOutput('Backfill customer 16')->doesntExpectOutput('Backfill customer 17')->assertSuccessful();

    expect(Company::query()->whereNotNull('credit_memos_backfilled_at')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects invalid batch limits', function (string $limit) {
    $this->artisan('milkstool:backfill-credit-memos', ['--limit' => $limit])->assertFailed();
    Http::assertNothingSent();
})->with(['0', '-1', '1001', 'abc']);

it('refuses concurrent backfills', function () {
    Cache::lock('netsuite-credit-memo-backfill', 86400)->get();
    $this->artisan('milkstool:backfill-credit-memos')->assertFailed();
    Http::assertNothingSent();
});
