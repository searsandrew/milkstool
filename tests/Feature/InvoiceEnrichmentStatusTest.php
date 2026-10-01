<?php

use App\Jobs\RefreshInvoiceDetails;
use App\Models\Company;
use App\Models\Transaction;
use App\Services\InvoiceEnrichmentStatus;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeSecond();
});

function enrichedInvoice(Company $company, int $id = 1347): Transaction
{
    return Transaction::factory()->for($company)->create(['id' => $id, 'type' => 'CustInvc',
        'invoice_details' => ['summary' => ['schema_version' => 1, 'header_updated_at' => '2026-09-01 12:00:00'], 'tracking_numbers' => ['OLD']]]);
}

it('refreshes tracking without rereading an already enriched invoice and clears its previous error', function () {
    $company = Company::factory()->create(['id' => 16, 'is_active' => true]);
    $invoice = enrichedInvoice($company);
    $failing = true;
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => function ($request) use (&$failing) {
        expect($request['q'])->toContain('itemfulfillmentpackage');

        return $failing ? Http::response(['private' => 'do not expose'], 503) : Http::response(sourcePage([]));
    }]);
    $job = (new RefreshInvoiceDetails(16))->withFakeQueueInteractions();

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RequestException::class);

    expect($invoice->refresh()->invoice_details['tracking_numbers'])->toBe(['OLD']);
    expect($invoice->invoice_details['enrichment_error'])->toMatchArray(['component' => 'tracking', 'http_status' => 503]);
    expect(json_encode($invoice->invoice_details['enrichment_error']))->not->toContain('private', 'do not expose');
    expect(app(InvoiceEnrichmentStatus::class)->report()['last_attempt_errors'])->toBe(1);
    $job->assertNotFailed();
    $failing = false;
    app()->call([$job, 'handle']);
    expect($invoice->refresh()->invoice_details['tracking_numbers'])->toBe([]);
    expect($invoice->invoice_details)->not->toHaveKey('enrichment_error');
    expect(app(InvoiceEnrichmentStatus::class)->report()['complete'])->toBeTrue();
    Http::assertSentCount(3);
});

it('retains a permanent enrichment failure for inspection and recovery', function () {
    $company = Company::factory()->create(['id' => 16, 'is_active' => true]);
    $invoice = Transaction::factory()->for($company)->create(['type' => 'CustInvc']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response([], 403)]);
    $job = (new RefreshInvoiceDetails(16))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertFailedWith(RequestException::class);
    expect($invoice->refresh()->invoice_details['enrichment_error']['component'])->toBe('summary');
    expect(app(InvoiceEnrichmentStatus::class)->report()['last_attempt_errors'])->toBe(1);
    Http::assertSentCount(1);
});

it('audits every active customer locally and queues bounded deduplicated recovery only when requested', function () {
    foreach ([16, 17] as $id) {
        enrichedInvoice(Company::factory()->create(['id' => $id, 'is_active' => true]), $id);
    }
    enrichedInvoice(Company::factory()->create(['id' => 18, 'is_active' => false]), 18);

    expect(Artisan::call('milkstool:invoice-enrichment', ['--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report)->toMatchArray(['invoices' => 2, 'summary_pending' => 0, 'tracking_due' => 2, 'complete' => false]);
    $this->assertDatabaseCount('jobs', 0);
    $this->artisan('milkstool:invoice-enrichment', ['--queue' => true, '--limit' => 1])->assertSuccessful();
    $this->artisan('milkstool:invoice-enrichment', ['--queue' => true, '--limit' => 1])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();
});

it('detects tracking changes independently of an unchanged invoice timestamp', function (bool $active, int $minutes, bool $due) {
    $company = Company::factory()->create(['id' => 16, 'is_active' => true, 'portal_last_active_at' => $active ? now() : null]);
    $invoice = enrichedInvoice($company);
    $invoice->update(['invoice_details' => [...$invoice->invoice_details, 'tracking_synced_at' => now()->subMinutes($minutes)->utc()->toIso8601String(), 'tracking_header_updated_at' => '2026-09-01 12:00:00']]);

    expect($invoice->hasCurrentInvoiceTracking())->toBe(! $due);
    expect(Transaction::query()->needsInvoiceTracking()->exists())->toBe($due);
    expect(app(InvoiceEnrichmentStatus::class)->report()['complete'])->toBe(! $due);
    Http::assertNothingSent();
})->with([[false, 359, false], [false, 360, true], [true, 14, false], [true, 15, true]]);

it('rejects invalid recovery targets without creating work', function () {
    Queue::fake();
    $this->artisan('milkstool:invoice-enrichment', ['--queue' => true, '--customer' => -1])->assertFailed();
    $this->artisan('milkstool:invoice-enrichment', ['--queue' => true, '--customer' => 999999])->assertFailed();
    $this->artisan('milkstool:invoice-enrichment', ['--queue' => true, '--limit' => 0])->assertFailed();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
