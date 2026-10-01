<?php

use App\Actions\StoreInvoice;
use App\Actions\SyncInvoices;
use App\Jobs\RefreshInvoiceDetails;
use App\Models\Company;
use App\Models\Transaction;
use App\Services\NetSuite\InvoiceTrackingSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->travelTo('2026-09-16 12:00:00');
});

it('pages changed fulfillments without requiring an existing package and scopes every join to the customer', function () {
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([['invoice_id' => '1347', 'customer_id' => '16']], true))
        ->push(sourcePage([['invoice_id' => '1348', 'customer_id' => '16']])), true)]);

    $ids = iterator_to_array(app(InvoiceTrackingSource::class)->changedInvoices(16,
        CarbonImmutable::parse('2026-09-16 09:55:00'), CarbonImmutable::parse('2026-09-16 11:58:00')));

    expect($ids)->toBe([1347, 1348]);
    Http::assertSent(fn ($request) => str_contains($request['q'], 'invoice.id > 1347')
        && str_contains($request['q'], 'salesOrder.entity = 16') && str_contains($request['q'], 'fulfillment.entity = 16')
        && str_contains($request['q'], "TO_TIMESTAMP('2026-09-16 09:55:00'")
        && ! str_contains($request['q'], 'JOIN itemfulfillmentpackage'));
    Http::assertSentCount(2);
});

it('rejects foreign customer or nonadvancing fulfillment results', function (array $rows) {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage($rows))]);

    expect(fn () => iterator_to_array(app(InvoiceTrackingSource::class)->changedInvoices(16,
        CarbonImmutable::parse('2026-09-16 09:55:00'), CarbonImmutable::parse('2026-09-16 11:58:00'))))->toThrow(Exception::class);
})->with([
    'foreign customer' => [[['invoice_id' => '1347', 'customer_id' => '17']]],
    'duplicate' => [[['invoice_id' => '1347', 'customer_id' => '16'], ['invoice_id' => '1347', 'customer_id' => '16']]],
]);

it('refreshes unchanged invoices inline when fulfillment changes and safely clears removed tracking numbers', function () {
    Queue::fake([RefreshInvoiceDetails::class]);
    $company = Company::factory()->create(['id' => 16, 'invoices_backfilled_at' => now()->subDay(), 'invoices_checkpoint_at' => '2026-09-16 10:00:00', 'invoices_full_synced_at' => now()]);
    app(StoreInvoice::class)->handle($company, sourceInvoice(), [sourceInvoiceLine()]);
    $invoice = Transaction::query()->findOrFail(1347);
    $invoice->update(['invoice_details' => [...$invoice->invoice_details, 'tracking_synced_at' => now()->subMonth()->toIso8601String(),
        'summary' => ['schema_version' => 1, 'header_updated_at' => '2026-09-01 12:00:00'],
        'tracking_header_updated_at' => '2026-09-01 12:00:00', 'tracking_numbers' => ['OLD']]]);
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([]))->push(sourcePage([['invoice_id' => '1347', 'customer_id' => '16']]))
        ->push(sourcePage([['currency_id' => '1', 'invoice_count' => '1', 'paid_count' => '1', 'unpaid_count' => '1',
            'foreign_amount_paid' => '20', 'foreign_amount_unpaid' => '5.12345678', 'total' => '25.12345678', 'foreign_total' => '25.12345678']]))
        ->push(sourcePage([['currency_id' => '1', 'line_count' => '1', 'quantity_count' => '1', 'amount_count' => '1',
            'quantity' => '-2.5', 'amount' => '-25.12345678', 'detail_amount' => '-25.12345678']])), true)]);

    app(SyncInvoices::class)->handle(16, incremental: true);

    Queue::assertNotPushed(RefreshInvoiceDetails::class);
    expect($invoice->refresh()->hasCurrentInvoiceTracking())->toBeTrue();
    expect($invoice->invoice_details['tracking_numbers'])->toBe([]);
    expect($company->refresh()->invoices_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 11:58:00');
    Http::assertSentCount(8);
});

it('replays the change window if fulfillment discovery fails', function () {
    $company = Company::factory()->create(['id' => 16, 'invoices_backfilled_at' => now()->subDay(), 'invoices_checkpoint_at' => '2026-09-16 10:00:00',
        'invoices_full_synced_at' => now(), 'invoices_synced_at' => '2026-09-16 10:02:00']);
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([]))->push([], 503), true)]);

    $this->artisan('milkstool:sync-invoices', ['customer' => 16, '--incremental' => true])->assertFailed();

    expect($company->refresh()->invoices_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:00:00');
    expect($company->invoices_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:02:00');
});
