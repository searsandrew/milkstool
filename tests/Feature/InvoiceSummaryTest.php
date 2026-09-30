<?php

use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    Http::preventStrayRequests();
});

function summaryInvoice(): Transaction
{
    return Transaction::factory()->for(Company::factory()->create(['id' => 16]))->create([
        'id' => 1347, 'type' => 'CustInvc', 'currency_id' => 1, 'foreign_total' => '105',
        'netsuite_updated_at' => '2026-09-01 12:00:45', 'invoice_details' => ['shipping_method' => 'UPS Ground'],
    ]);
}

function summaryRecord(): array
{
    return ['id' => '1347', 'entity' => ['id' => '16'], 'currency' => ['id' => '1'],
        'lastModifiedDate' => '2026-09-01T12:00:00Z', 'subtotal' => '110', 'discountTotal' => '-10',
        'taxTotal' => '0', 'shippingCost' => '5', 'total' => '105'];
}

it('copies source summary values preserving zero missing values and discounts', function () {
    $invoice = summaryInvoice();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([sourceInvoice(['updated_at' => '2026-09-01 12:00:45'])])),
        'https://netsuite.example/services/rest/record/v1/invoice/1347*' => Http::response(summaryRecord())]);

    $this->artisan('milkstool:sync-invoice-summary', ['customer' => 16, 'invoice' => 1347])->assertSuccessful();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.invoice_summary.subtotal', '110.00000000')
        ->assertJsonPath('data.invoice_summary.discount_total', '-10.00000000')
        ->assertJsonPath('data.invoice_summary.tax_total', '0.00000000')
        ->assertJsonPath('data.invoice_summary.shipping_cost', '5.00000000')
        ->assertJsonPath('data.invoice_summary.handling_cost', null);
    expect($invoice->refresh()->invoice_details['shipping_method'])->toBe('UPS Ground');
    $invoice->update(['netsuite_updated_at' => '2026-09-02 12:00:00']);
    $this->getJson('/api/v1/customers/16/transactions/1347')->assertJsonPath('data.invoice_summary', null);
    Http::assertSentCount(3);
});

it('preserves existing data when the source mismatches or changes', function (string $field, mixed $value) {
    $invoice = summaryInvoice();
    $record = summaryRecord();
    data_set($record, $field, $value);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([sourceInvoice(['updated_at' => '2026-09-01 12:00:45'])])),
        'https://netsuite.example/services/rest/record/v1/invoice/1347*' => Http::response($record)]);

    $this->artisan('milkstool:sync-invoice-summary', ['customer' => 16, 'invoice' => 1347])->assertFailed();
    expect($invoice->refresh()->invoice_details)->toBe(['shipping_method' => 'UPS Ground']);
})->with([['entity.id', '17'], ['total', '106'], ['currency.id', '2'], ['lastModifiedDate', '2026-09-02T12:00:00Z'], ['taxTotal', 'invalid']]);

it('refuses invoices belonging to another customer without a source request', function () {
    summaryInvoice();
    $this->artisan('milkstool:sync-invoice-summary', ['customer' => 17, 'invoice' => 1347])->assertFailed();
    Http::assertNothingSent();
});
