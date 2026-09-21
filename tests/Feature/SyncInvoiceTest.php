<?php

use App\Actions\SyncInvoice;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceInvoice(array $overrides = []): array
{
    return array_replace(sourceOrder(), ['id' => '1347', 'type' => 'CustInvc', 'number' => 'INV01',
        'due_date' => '2026-09-30', 'foreign_amount_paid' => '20', 'foreign_amount_unpaid' => '5.12345678'], $overrides);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceInvoiceLine(array $overrides = []): array
{
    return array_replace(sourceLine(), ['transaction_id' => '1347', 'source_transaction_id' => '101'], $overrides);
}

/** @param list<array<string, mixed>> $lines
 * @param  array<string, mixed>  $header
 */
function fakeSingleInvoice(array $lines, array $header = []): void
{
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceInvoice($header)]))->push(sourcePage($lines))->push(sourcePage([sourceInvoice($header)]))]);
}

it('imports an invoice with exact monetary fields, nullable lines and source references without touching order freshness', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'sales_orders_synced_at' => '2026-09-01 12:00:00']);
    $order = Transaction::factory()->for($company)->create(['netsuite_id' => 101]);
    fakeSingleInvoice([sourceInvoiceLine(['line_id' => '0', 'mainline' => 'T', 'quantity' => null]), sourceInvoiceLine()]);

    $this->artisan('milkstool:sync-invoice', ['customer' => '16', 'invoice' => '1347'])->assertSuccessful();

    $invoice = Transaction::query()->where('netsuite_id', 1347)->sole();
    expect($invoice->type)->toBe('CustInvc');
    expect($invoice->foreign_amount_paid)->toBe('20.00000000');
    expect($invoice->foreign_amount_unpaid)->toBe('5.12345678');
    expect($invoice->due_date->format('Y-m-d'))->toBe('2026-09-30');
    expect($invoice->lines()->where('netsuite_line_id', 0)->sole()->quantity)->toBeNull();
    expect($invoice->lines()->where('netsuite_line_id', 1)->sole()->quantity)->toBe('-2.50000000');
    expect($invoice->lines()->where('netsuite_line_id', 1)->sole()->source_transaction_id)->toBe(101);
    expect($company->refresh()->sales_orders_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($order->refresh()->total)->toBe('100.00000000');
    Http::assertSentCount(3);
});

it('updates payment snapshots and removes absent lines only after a complete reimport', function () {
    Company::factory()->create(['netsuite_id' => 16]);
    $paid = sourceInvoice(['foreign_amount_paid' => '25.12345678', 'foreign_amount_unpaid' => '0', 'due_date' => null]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceInvoice()]))->push(sourcePage([sourceInvoiceLine(), sourceInvoiceLine(['line_id' => '2'])]))->push(sourcePage([sourceInvoice()]))
        ->push(sourcePage([$paid]))->push(sourcePage([sourceInvoiceLine()]))->push(sourcePage([$paid]))]);
    app(SyncInvoice::class)->handle(16, 1347);

    app(SyncInvoice::class)->handle(16, 1347);

    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('transaction_lines', 1);
    expect(Transaction::query()->sole()->foreign_amount_unpaid)->toBe('0.00000000');
    expect(Transaction::query()->sole()->due_date)->toBeNull();
    Http::assertSentCount(6);
});

it('retains the existing invoice when a later line page fails', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $invoice = Transaction::factory()->for($company)->create(['netsuite_id' => 1347, 'type' => 'CustInvc']);
    $line = TransactionLine::factory()->for($invoice)->create();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceInvoice()]))->push(sourcePage([sourceInvoiceLine()], true))->push([], 503)]);

    $this->artisan('milkstool:sync-invoice', ['customer' => '16', 'invoice' => '1347'])->assertFailed();

    $this->assertModelExists($line);
    expect($invoice->refresh()->total)->toBe('100.00000000');
    expect(Cache::lock('netsuite-invoices:16', 600)->get())->toBeTrue();
    Http::assertSentCount(3);
});

it('refuses a payment change during retrieval even if the source modification timestamp is unchanged', function () {
    Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceInvoice()]))->push(sourcePage([sourceInvoiceLine()]))
        ->push(sourcePage([sourceInvoice(['foreign_amount_unpaid' => '0'])]))]);

    $this->artisan('milkstool:sync-invoice', ['customer' => '16', 'invoice' => '1347'])->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
    Http::assertSentCount(3);
});

it('refuses malformed or foreign invoice headers before writing', function (array $overrides) {
    Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([sourceInvoice($overrides)]))]);

    $this->artisan('milkstool:sync-invoice', ['customer' => '16', 'invoice' => '1347'])->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
    Http::assertSentCount(1);
})->with([[['customer_id' => '17']], [['id' => '1348']], [['type' => 'SalesOrd']], [['foreign_amount_unpaid' => 'invalid']]]);

it('refuses empty, foreign or duplicate lines without persisting an invoice', function (array $lines) {
    Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceInvoice()]))->push(sourcePage($lines))]);

    $this->artisan('milkstool:sync-invoice', ['customer' => '16', 'invoice' => '1347'])->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
    Http::assertSentCount(2);
})->with([
    'empty' => [[]],
    'foreign' => [[sourceInvoiceLine(['transaction_id' => '1348'])]],
    'duplicate' => [[sourceInvoiceLine(), sourceInvoiceLine()]],
]);

it('does not overwrite another customer or transaction type', function (bool $otherCustomer) {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $owner = $otherCustomer ? Company::factory()->create(['netsuite_id' => 17]) : $company;
    $existing = Transaction::factory()->for($owner)->create(['netsuite_id' => 1347, 'type' => $otherCustomer ? 'CustInvc' : 'SalesOrd']);
    fakeSingleInvoice([sourceInvoiceLine()]);

    $this->artisan('milkstool:sync-invoice', ['customer' => '16', 'invoice' => '1347'])->assertFailed();

    expect($existing->refresh()->total)->toBe('100.00000000');
    expect($existing->company_id)->toBe($owner->id);
    $this->assertDatabaseCount('transaction_lines', 0);
})->with([true, false]);

it('paginates invoice lines and preserves unknown monetary amounts as null', function () {
    Company::factory()->create(['netsuite_id' => 16]);
    $header = sourceInvoice(['foreign_amount_paid' => null, 'foreign_amount_unpaid' => null]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([$header]))->push(sourcePage([sourceInvoiceLine(['line_id' => '0'])], true))
        ->push(sourcePage([sourceInvoiceLine()]))->push(sourcePage([$header]))]);

    app(SyncInvoice::class)->handle(16, 1347);

    expect(Transaction::query()->sole()->foreign_amount_unpaid)->toBeNull();
    $this->assertDatabaseCount('transaction_lines', 2);
    Http::assertSent(fn ($request) => str_contains($request['q'], 'transactionline.id > 0'));
});

it('refuses concurrent imports and unregistered customers before contacting NetSuite', function (bool $registered) {
    if ($registered) {
        Company::factory()->create(['netsuite_id' => 16]);
        Cache::lock('netsuite-invoices:16', 600)->get();
    }

    $this->artisan('milkstool:sync-invoice', ['customer' => '16', 'invoice' => '1347'])->assertFailed();
    Http::assertNothingSent();
})->with([true, false]);

it('rejects invalid IDs', function (string $customer, string $invoice) {
    $this->artisan('milkstool:sync-invoice', ['customer' => $customer, 'invoice' => $invoice])->assertFailed();
    Http::assertNothingSent();
})->with([['0', '1347'], ['16', '-1'], ['16 OR 1=1', '1347']]);
