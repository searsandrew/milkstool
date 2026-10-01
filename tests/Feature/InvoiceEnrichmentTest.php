<?php

use App\Actions\StoreInvoice;
use App\Actions\SyncInvoice;
use App\Exceptions\ReceivableSyncInterrupted;
use App\Jobs\RefreshInvoiceDetails;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeSecond();
});

it('stores a complete invoice and exposes its enrichment state without a second manual sync', function () {
    Company::factory()->create(['id' => 16]);
    fakeSingleInvoice([sourceInvoiceLine()]);

    $invoice = app(SyncInvoice::class)->handle(16, 1347);

    expect($invoice->hasCurrentInvoiceEnrichment())->toBeTrue();
    expect(Transaction::query()->needsInvoiceEnrichment()->exists())->toBeFalse();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.invoice_enrichment.status', 'current')
        ->assertJsonPath('data.invoice_summary.shipping_cost', '0.00000000')
        ->assertJsonPath('data.invoice_summary.line_quantities.0.quantity_remaining', '3.00000000');
    $invoice->update(['netsuite_updated_at' => now()]);
    expect(Transaction::query()->needsInvoiceEnrichment()->count())->toBe(1);
    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.invoice_enrichment.status', 'pending')->assertJsonPath('data.invoice_summary', null);
});

it('retains the entire old snapshot when summary or item verification fails', function (string $field, mixed $value) {
    $company = Company::factory()->create(['id' => 16]);
    $invoice = Transaction::factory()->for($company)->create(['id' => 1347, 'type' => 'CustInvc', 'invoice_details' => ['summary' => ['subtotal' => '100']]]);
    $line = TransactionLine::factory()->for($invoice)->create(['netsuite_line_id' => 99]);
    $old = $invoice->refresh()->getAttributes();
    $record = sourceInvoiceRecord();
    data_set($record, $field, $value);
    Http::fake([
        'https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()->push(sourcePage([sourceInvoice()]))->push(sourcePage([sourceInvoiceLine()])),
        'https://netsuite.example/services/rest/record/v1/invoice/1347*' => Http::response($record),
    ]);

    expect(fn () => app(SyncInvoice::class)->handle(16, 1347))->toThrow(ReceivableSyncInterrupted::class);

    expect($invoice->refresh()->getAttributes())->toBe($old);
    $this->assertModelExists($line);
    $this->assertDatabaseCount('transaction_lines', 1);
})->with([
    ['total', '999'], ['currency.id', '2'], ['lastModifiedDate', '2026-09-02T12:00:00Z'],
    ['item.items.0.item.id', 999], ['item.items.0.line', 999],
    ['item', ['items' => [], 'totalResults' => 0]],
]);

it('invalidates enrichment when lines or totals change without a modification timestamp change', function (bool $changeLines) {
    $company = Company::factory()->create(['id' => 16]);
    fakeSingleInvoice([sourceInvoiceLine()]);
    $invoice = app(SyncInvoice::class)->handle(16, 1347);
    $header = sourceInvoice($changeLines ? [] : ['foreign_total' => '500']);
    $lines = [sourceInvoiceLine($changeLines ? ['item_id' => '999'] : [])];

    app(StoreInvoice::class)->handle($company, $header, $lines);

    expect($invoice->refresh()->hasCurrentInvoiceEnrichment())->toBeFalse();
    expect(Transaction::query()->needsInvoiceEnrichment()->count())->toBe(1);
    expect($invoice->invoice_details['summary'])->not->toHaveKey('source_modified_at');
})->with([true, false]);

it('processes five invoices then schedules a continuation and skips completed invoices', function () {
    $company = Company::factory()->create(['id' => 16, 'is_active' => true]);
    foreach (range(1347, 1352) as $id) {
        Transaction::factory()->for($company)->create(['id' => $id, 'type' => 'CustInvc']);
    }
    Http::fake([
        'https://netsuite.example/services/rest/query/v1/suiteql*' => function ($request) {
            if (str_contains($request['q'], 'FROM transactionline')) {
                preg_match('/transactionline.transaction IN \((\d+)\)/', $request['q'], $match);

                return Http::response(sourcePage([sourceInvoiceLine(['transaction_id' => $match[1]])]));
            }
            preg_match('/AND id = (\d+)/', $request['q'], $match);

            return Http::response(sourcePage([sourceInvoice(['id' => $match[1]])]));
        },
        'https://netsuite.example/services/rest/record/v1/invoice/*' => function ($request) {
            preg_match('~/invoice/(\d+)~', $request->url(), $match);

            return Http::response(sourceInvoiceRecord(header: ['id' => $match[1]]));
        },
    ]);
    RefreshInvoiceDetails::dispatch(16);

    Queue::connection('netsuite')->pop('invoices')->fire();

    expect(Transaction::query()->needsInvoiceEnrichment()->pluck('id')->all())->toBe([1347]);
    $this->assertDatabaseCount('jobs', 1);
    expect(DB::table('jobs')->sole()->available_at)->toBe(now()->addSeconds(30)->timestamp);
    $this->travel(31)->seconds();
    Queue::connection('netsuite')->pop('invoices')->fire();
    expect(Transaction::query()->needsInvoiceEnrichment()->exists())->toBeFalse();
    $this->assertDatabaseCount('jobs', 0);
    Http::assertSentCount(30);
});

it('skips inactive customers without fetching or queueing more enrichment', function () {
    $company = Company::factory()->create(['id' => 16, 'is_active' => false]);
    Transaction::factory()->for($company)->create(['type' => 'CustInvc']);
    Queue::fake();

    (new RefreshInvoiceDetails(16))->handle(app(SyncInvoice::class));

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
