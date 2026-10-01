<?php

use App\Actions\SyncInvoices;
use App\Jobs\RefreshInvoiceDetails;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('stores complete first-import invoices without scheduling a second detail pass', function () {
    Queue::fake();
    $company = Company::factory()->create(['id' => 16]);
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceInvoice()]))
        ->push(sourcePage([sourceInvoiceLine()]))->push(sourcePage([sourceInvoice()]))
        ->push(sourcePage([invoiceHeaderTotals()]))->push(sourcePage([invoiceLineTotals()])))]);

    $result = app(SyncInvoices::class)->handle(16, incremental: true);

    $invoice = Transaction::findOrFail(1347);
    expect($result['complete'])->toBeTrue()
        ->and($invoice->hasCurrentInvoiceEnrichment())->toBeTrue()
        ->and($invoice->hasCurrentInvoiceTracking())->toBeTrue()
        ->and($company->transactions()->needsInvoiceWork()->exists())->toBeFalse()
        ->and($company->refresh()->invoices_backfilled_at)->not->toBeNull()
        ->and($company->invoices_import_state)->toBeNull();
    Queue::assertNotPushed(RefreshInvoiceDetails::class);
});

it('resumes after a committed batch without fetching its details again', function () {
    $company = Company::factory()->create(['id' => 16]);
    $headers = array_map(fn (int $id): array => sourceInvoice(['id' => (string) $id]), range(1347, 1396));
    $lines = array_map(fn (int $id): array => sourceInvoiceLine(['transaction_id' => (string) $id]), range(1347, 1396));
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage($headers, true))
        ->push(sourcePage($lines))->push(sourcePage($headers))
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))
        ->push(sourcePage([invoiceHeaderTotals(['invoice_count' => '50', 'paid_count' => '50', 'unpaid_count' => '50',
            'foreign_amount_paid' => '1000', 'foreign_amount_unpaid' => '256.172839', 'total' => '1256.172839', 'foreign_total' => '1256.172839'])]))
        ->push(sourcePage([invoiceLineTotals(['line_count' => '50', 'quantity_count' => '50', 'amount_count' => '50',
            'quantity' => '-125', 'amount' => '-1256.172839', 'detail_amount' => '-1256.172839'])])))]);

    $first = app(SyncInvoices::class)->handle(16, incremental: true, maxBatches: 1);
    expect($first['complete'])->toBeFalse()
        ->and($company->refresh()->invoices_import_state['cursor'])->toBe(1396)
        ->and($company->invoices_backfilled_at)->toBeNull()
        ->and($company->transactions()->needsInvoiceWork()->exists())->toBeFalse();
    $second = app(SyncInvoices::class)->handle(16, incremental: true, maxBatches: 1);

    expect($second['complete'])->toBeTrue()
        ->and($second['invoices'])->toBe(50)
        ->and($company->refresh()->invoices_import_state)->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request['q'] ?? '', 'AND id > 1396 ORDER BY id'));
    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/record/v1/invoice/')))->toHaveCount(100);
    expect(Http::recorded(fn ($request) => str_contains($request['q'] ?? '', 'CURRENT_TIMESTAMP')))->toHaveCount(1);
});

it('does not publish partial invoices when their details cannot be fetched', function () {
    $company = Company::factory()->create(['id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/record/v1/invoice/*' => Http::response([], 503),
        'https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
            ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceInvoice()]))->push(sourcePage([sourceInvoiceLine()])))]);

    $this->artisan('milkstool:sync-invoices', ['customer' => 16])->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
    expect($company->refresh()->invoices_import_state['cursor'])->toBe(0)
        ->and($company->invoices_checkpoint_at)->toBeNull()
        ->and($company->invoices_backfilled_at)->toBeNull();
});
