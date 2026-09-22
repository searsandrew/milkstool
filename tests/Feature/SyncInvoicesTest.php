<?php

use App\Actions\SyncInvoices;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\NetSuite\InvoiceSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function invoiceHeaderTotals(array $overrides = []): array
{
    return array_replace(['currency_id' => '1', 'invoice_count' => '1', 'paid_count' => '1', 'unpaid_count' => '1',
        'foreign_amount_paid' => '20', 'foreign_amount_unpaid' => '5.12345678', 'total' => '25.12345678', 'foreign_total' => '25.12345678'], $overrides);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function invoiceLineTotals(array $overrides = []): array
{
    return array_replace(['currency_id' => '1', 'line_count' => '1', 'quantity_count' => '1', 'amount_count' => '1',
        'quantity' => '-2.5', 'amount' => '-25.12345678', 'detail_amount' => '-25.12345678'], $overrides);
}

it('batches invoices and reconciles currencies without mixing sales orders or other customers', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    Transaction::factory()->for($company)->create();
    Transaction::factory()->create(['type' => 'CustInvc']);
    $second = sourceInvoice(['id' => '1348', 'currency_id' => '2']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceInvoice(), $second]))
        ->push(sourcePage([sourceInvoiceLine(), sourceInvoiceLine(['transaction_id' => '1348'])]))
        ->push(sourcePage([sourceInvoice(), $second]))
        ->push(sourcePage([invoiceHeaderTotals(), invoiceHeaderTotals(['currency_id' => '2'])]))
        ->push(sourcePage([invoiceLineTotals(), invoiceLineTotals(['currency_id' => '2'])]))]);

    $result = app(SyncInvoices::class)->handle(16);

    expect($result['invoices'])->toBe(2);
    expect($result['lines'])->toBe(2);
    expect($result['reconciliation'])->toHaveCount(26);
    expect(collect($result['reconciliation'])->every('matches'))->toBeTrue();
    expect($company->refresh()->invoices_synced_at)->not->toBeNull();
    expect($company->sales_orders_synced_at)->toBeNull();
    Http::assertSentCount(6);
});

it('refreshes unchanged modification timestamps and payment snapshots without duplicating invoices', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $existing = Transaction::factory()->for($company)->create(['netsuite_id' => 1347, 'type' => 'CustInvc',
        'netsuite_updated_at' => '2026-09-01 12:00:00', 'foreign_amount_paid' => '0', 'foreign_amount_unpaid' => '25.12345678']);
    TransactionLine::factory()->for($existing)->create(['netsuite_line_id' => 9]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceInvoice()]))->push(sourcePage([sourceInvoiceLine()]))
        ->push(sourcePage([sourceInvoice()]))->push(sourcePage([invoiceHeaderTotals()]))->push(sourcePage([invoiceLineTotals()]))]);

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertSuccessful();

    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('transaction_lines', 1);
    expect($existing->refresh()->foreign_amount_paid)->toBe('20.00000000');
    expect($existing->lines()->sole()->netsuite_line_id)->toBe(1);
    Http::assertSentCount(6);
});

it('preserves last success when reconciliation fails', function (array $header, array $lines) {
    $company = Company::factory()->create(['netsuite_id' => 16, 'invoices_synced_at' => '2026-09-01 12:00:00']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceInvoice()]))->push(sourcePage([sourceInvoiceLine()]))
        ->push(sourcePage([sourceInvoice()]))->push(sourcePage([invoiceHeaderTotals($header)]))->push(sourcePage([invoiceLineTotals($lines)]))]);

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertFailed();

    expect($company->refresh()->invoices_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($company->invoices_sync_error)->not->toBeNull();
    expect(Cache::lock('netsuite-invoices:16', 600)->get())->toBeTrue();
    Http::assertSentCount(6);
})->with([
    'payment changed' => [['foreign_amount_unpaid' => '0'], []],
    'null count differs' => [['paid_count' => '0'], []],
    'line amount differs' => [[], ['detail_amount' => '-99']],
]);

it('keeps missing source invoices and rejects a false successful sync', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $missing = Transaction::factory()->for($company)->create(['type' => 'CustInvc']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))]);

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertFailed();

    $this->assertModelExists($missing);
    expect($company->refresh()->invoices_synced_at)->toBeNull();
});

it('records a reconciled empty history only after validating the customer', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([]))]);

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertSuccessful();

    expect($company->refresh()->invoices_synced_at)->not->toBeNull();
    Http::assertSentCount(4);
});

it('does not replace old lines when a batch line page fails', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $invoice = Transaction::factory()->for($company)->create(['netsuite_id' => 1347, 'type' => 'CustInvc']);
    $line = TransactionLine::factory()->for($invoice)->create();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceInvoice()]))
        ->push(sourcePage([sourceInvoiceLine()], true))->push([], 503)]);

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertFailed();

    $this->assertModelExists($line);
    expect($invoice->refresh()->total)->toBe('100.00000000');
    expect($company->refresh()->invoices_synced_at)->toBeNull();
});

it('rejects changed or missing verification headers before saving a batch', function (array $verification) {
    Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceInvoice()]))->push(sourcePage([sourceInvoiceLine()]))
        ->push(sourcePage($verification))]);

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
})->with(['missing' => [[]], 'payment changed' => [[sourceInvoice(['foreign_amount_unpaid' => '0'])]]]);

it('paginates customer invoices and refuses duplicate header pages', function (bool $duplicate) {
    $second = sourceInvoice(['id' => $duplicate ? '1347' : '1348']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceInvoice()], true))->push(sourcePage([$second]))]);

    if ($duplicate) {
        expect(fn () => iterator_to_array(app(InvoiceSource::class)->invoices(16)))->toThrow(RuntimeException::class);
    } else {
        expect(iterator_to_array(app(InvoiceSource::class)->invoices(16)))->toHaveCount(2);
    }
    Http::assertSent(fn ($request) => str_contains($request['q'], 'id > 1347 ORDER BY id'));
})->with([true, false]);

it('rejects truncated reconciliation totals', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))
        ->push(sourcePage([invoiceHeaderTotals()], true))->push(sourcePage([]))]);

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertFailed();

    expect($company->refresh()->invoices_synced_at)->toBeNull();
});

it('shares the single-invoice lock and does not change sync state on contention', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    Cache::lock('netsuite-invoices:16', 600)->get();

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertFailed();

    expect($company->refresh()->invoices_sync_started_at)->toBeNull();
    Http::assertNothingSent();
});

it('keeps completed batches but leaves freshness unchanged when a later page fails', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'invoices_synced_at' => '2026-09-01 12:00:00']);
    $invoices = array_map(fn (int $id): array => sourceInvoice(['id' => (string) $id]), range(1347, 1396));
    $lines = array_map(fn (int $id): array => sourceInvoiceLine(['transaction_id' => (string) $id]), range(1347, 1396));
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage($invoices, true))
        ->push(sourcePage($lines))->push(sourcePage($invoices))->push([], 503)]);

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertFailed();

    $this->assertDatabaseCount('transactions', 50);
    $this->assertDatabaseCount('transaction_lines', 50);
    expect($company->refresh()->invoices_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($company->invoices_sync_error)->not->toBeNull();
    Http::assertSentCount(5);
});

it('reconciles unknown paid amounts without treating null as a known zero', function () {
    Company::factory()->create(['netsuite_id' => 16]);
    $invoice = sourceInvoice(['foreign_amount_paid' => null, 'foreign_amount_unpaid' => null]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([$invoice]))->push(sourcePage([sourceInvoiceLine()]))
        ->push(sourcePage([$invoice]))
        ->push(sourcePage([invoiceHeaderTotals(['paid_count' => '0', 'unpaid_count' => '0', 'foreign_amount_paid' => '0', 'foreign_amount_unpaid' => '0'])]))
        ->push(sourcePage([invoiceLineTotals()]))]);

    $this->artisan('milkstool:sync-invoices', ['customer' => '16'])->assertSuccessful();

    expect(Transaction::query()->sole()->foreign_amount_paid)->toBeNull();
    expect(Transaction::query()->sole()->foreign_amount_unpaid)->toBeNull();
});

it('rejects invalid or unregistered customers without contacting NetSuite', function (string $customer) {
    $this->artisan('milkstool:sync-invoices', ['customer' => $customer])->assertFailed();
    Http::assertNothingSent();
})->with(['0', '16 OR 1=1', '16']);
