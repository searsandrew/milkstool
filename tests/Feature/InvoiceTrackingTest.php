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

it('mirrors deduplicated tracking without changing financial or header freshness', function () {
    $company = Company::factory()->create(['id' => 16]);
    $invoice = Transaction::factory()->for($company)->create(['id' => 1347, 'type' => 'CustInvc', 'invoice_details' => ['terms_name' => 'Net 30']]);
    $synced = $invoice->synced_at;
    $rows = [['invoice_id' => '1347', 'customer_id' => '16', 'tracking_number' => ' 1Z123 '], ['invoice_id' => '1347', 'customer_id' => '16', 'tracking_number' => '1Z123']];
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage($rows))]);

    $this->artisan('milkstool:sync-invoice-tracking', ['customer' => 16])->assertSuccessful();

    expect($invoice->refresh()->invoice_details)->toMatchArray(['terms_name' => 'Net 30', 'tracking_numbers' => ['1Z123'], 'tracking_scope' => 'related_sales_orders'])
        ->and($invoice->synced_at->equalTo($synced))->toBeTrue()
        ->and($invoice->invoice_details_synced_at)->toBeNull();
    $invoice->fillInvoiceDetails(['terms_name' => 'Updated terms']);
    $invoice->save();
    expect($invoice->refresh()->invoice_details['tracking_numbers'])->toBe(['1Z123']);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/transactions/1347')->assertJsonPath('data.invoice_details.tracking_numbers', ['1Z123']);
    Http::assertSent(fn ($request) => str_contains($request['q'], 'invoice.entity = 16') && str_contains($request['q'], 'salesOrder.entity = 16') && str_contains($request['q'], 'fulfillment.entity = 16'));
    Http::assertSentCount(2);
});

it('preserves tracking on incomplete changing or foreign source results', function (array $first, array $second) {
    $company = Company::factory()->create(['id' => 16]);
    $invoice = Transaction::factory()->for($company)->create(['id' => 1347, 'type' => 'CustInvc', 'invoice_details' => ['tracking_numbers' => ['OLD']]]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()->push($first)->push($second)]);

    $this->artisan('milkstool:sync-invoice-tracking', ['customer' => 16, '--invoice' => 1347])->assertFailed();

    expect($invoice->refresh()->invoice_details['tracking_numbers'])->toBe(['OLD']);
    Http::assertSent(fn ($request) => str_contains($request['q'], 'invoice.id IN (1347)'));
})->with([
    [array_replace(sourcePage([]), ['hasMore' => true]), sourcePage([])],
    [sourcePage([]), sourcePage([['invoice_id' => '1347', 'customer_id' => '16', 'tracking_number' => 'NEW']])],
    [sourcePage([['invoice_id' => '1347', 'customer_id' => '17', 'tracking_number' => 'FOREIGN']]), sourcePage([])],
]);

it('records an empty tracking list only after a successful source read', function () {
    $company = Company::factory()->create(['id' => 16]);
    $invoice = Transaction::factory()->for($company)->create(['id' => 1347, 'type' => 'CustInvc']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([]))]);

    $this->artisan('milkstool:sync-invoice-tracking', ['customer' => 16])->assertSuccessful();

    expect($invoice->refresh()->invoice_details['tracking_numbers'])->toBe([]);
    Http::assertSentCount(2);
});

it('does not request an invoice belonging to a different customer', function () {
    Company::factory()->create(['id' => 16]);
    Transaction::factory()->create(['id' => 1347, 'type' => 'CustInvc']);

    $this->artisan('milkstool:sync-invoice-tracking', ['customer' => 16, '--invoice' => 1347])->assertFailed();

    Http::assertNothingSent();
});
