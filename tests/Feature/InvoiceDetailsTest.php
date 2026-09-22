<?php

use App\Actions\SyncInvoice;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('mirrors invoice addresses and terms and exposes them only on authorized invoice details', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    fakeSingleInvoice([sourceInvoiceLine()], ['billing_address' => "Historical Billing\n123 Old Street", 'shipping_address' => 'Original ship-to',
        'terms_id' => '2', 'terms_name' => 'Net 30', 'ship_date' => '2026-08-01', 'shipping_method' => 'Ground']);
    $invoice = app(SyncInvoice::class)->handle(16, 1347);
    expect($invoice->invoice_details)->toMatchArray(['billing_address' => "Historical Billing\n123 Old Street", 'terms_id' => 2]);
    expect($invoice->invoice_details_synced_at)->not->toBeNull();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);

    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.invoice_details.billing_address', "Historical Billing\n123 Old Street")
        ->assertJsonPath('data.invoice_details.shipping_address', 'Original ship-to')
        ->assertJsonPath('data.invoice_details.terms_id', 2)->assertJsonPath('data.invoice_details.terms_name', 'Net 30')
        ->assertJsonPath('data.invoice_details.ship_date', '2026-08-01')->assertJsonPath('data.invoice_details.shipping_method', 'Ground');
    $this->getJson('/api/v1/customers/16/transactions')->assertOk()->assertJsonMissingPath('data.0.invoice_details');
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:17']);
    $this->getJson('/api/v1/customers/16/transactions/1347')->assertForbidden()->assertDontSee('Historical Billing');
    Http::assertSentCount(3);
});

it('distinguishes legacy invoices from synced invoices whose optional details are absent', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $invoice = Transaction::factory()->for($company)->create(['netsuite_id' => 1347, 'type' => 'CustInvc']);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/transactions/1347')->assertJsonPath('data.invoice_details', null);
    $invoice->forceFill(['invoice_details' => ['billing_address' => 'Old address']])->save();
    fakeSingleInvoice([sourceInvoiceLine()]);

    app(SyncInvoice::class)->handle(16, 1347);

    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.invoice_details.billing_address', null)
        ->assertJsonPath('data.invoice_details.terms_id', null);
    expect($invoice->refresh()->invoice_details_synced_at)->not->toBeNull();
});

it('backfills only missing invoice headers without changing lines balances or financial freshness and skips completed work', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'invoices_synced_at' => '2026-09-01 12:00:00']);
    $invoice = Transaction::factory()->for($company)->create(['netsuite_id' => 1347, 'type' => 'CustInvc', 'foreign_amount_unpaid' => '99', 'synced_at' => '2026-09-01 12:00:00']);
    $line = TransactionLine::factory()->for($invoice)->create();
    Transaction::factory()->for($company)->create(['type' => 'CustCred']);
    Transaction::factory()->for($company)->create(['type' => 'CustInvc', 'invoice_details_synced_at' => now(), 'invoice_details' => ['terms_name' => 'Already imported']]);
    $header = sourceInvoice(['billing_address' => 'Original billing', 'foreign_amount_unpaid' => '0']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()->push(sourcePage([$header]))->push(sourcePage([$header]))]);

    $this->artisan('milkstool:backfill-invoice-details')->assertSuccessful();

    expect($invoice->refresh()->invoice_details['billing_address'])->toBe('Original billing');
    expect($invoice->foreign_amount_unpaid)->toBe('99.00000000');
    expect($invoice->synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($company->refresh()->invoices_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    $this->assertModelExists($line);
    $this->artisan('milkstool:backfill-invoice-details')->expectsOutput('Invoice details backfill complete: 0 invoices updated.')->assertSuccessful();
    Http::assertSentCount(2);
});

it('leaves the batch resumable and releases locks when headers change or retrieval fails', function (bool $changed) {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $invoice = Transaction::factory()->for($company)->create(['netsuite_id' => 1347, 'type' => 'CustInvc']);
    $sequence = Http::sequence()->push(sourcePage([sourceInvoice()]));
    if ($changed) {
        $sequence->push(sourcePage([sourceInvoice(['billing_address' => 'Changed address'])]));
    } else {
        $sequence->push([], 403);
    }
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => $sequence]);

    $this->artisan('milkstool:backfill-invoice-details')->assertFailed();

    expect($invoice->refresh()->invoice_details_synced_at)->toBeNull();
    expect($invoice->invoice_details)->toBeNull();
    foreach (['netsuite-invoice-details-backfill', 'netsuite-invoices:16'] as $key) {
        $lock = Cache::lock($key, 600);
        expect($lock->get())->toBeTrue();
        $lock->release();
    }
})->with([true, false]);

it('previews bounded active customers without contacting NetSuite', function () {
    foreach ([[17, true], [16, true], [15, false]] as [$id, $active]) {
        $company = Company::factory()->create(['netsuite_id' => $id, 'is_active' => $active]);
        Transaction::factory()->for($company)->create(['type' => 'CustInvc']);
    }

    $this->artisan('milkstool:backfill-invoice-details', ['--limit' => '1', '--dry-run' => true])
        ->expectsOutput('Invoice details for customer 16')->doesntExpectOutput('Invoice details for customer 17')
        ->doesntExpectOutput('Invoice details for customer 15')->assertSuccessful();
    Http::assertNothingSent();
});

it('respects backfill and customer invoice locks', function (string $key) {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    Transaction::factory()->for($company)->create(['type' => 'CustInvc']);
    $lock = Cache::lock($key, 600);
    $lock->get();

    $this->artisan('milkstool:backfill-invoice-details')->assertFailed();

    expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    Http::assertNothingSent();
    $lock->release();
})->with(['netsuite-invoice-details-backfill', 'netsuite-invoices:16']);

it('rejects invalid invoice detail batch limits', function (string $limit) {
    $this->artisan('milkstool:backfill-invoice-details', ['--limit' => $limit])->assertFailed();
    Http::assertNothingSent();
})->with(['0', '-1', '1001', 'invalid']);

it('validates optional invoice display fields before persisting them', function (array $fields) {
    Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([sourceInvoice($fields)]))]);

    $this->artisan('milkstool:sync-invoice', ['customer' => '16', 'invoice' => '1347'])->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
})->with([[['terms_id' => '-1']], [['billing_address' => ['invalid']]], [['ship_date' => 'not-a-date']]]);

it('resumes after a later batch fails without reloading completed invoices', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $headers = [];
    for ($id = 1347; $id < 1398; $id++) {
        Transaction::factory()->for($company)->create(['netsuite_id' => $id, 'type' => 'CustInvc']);
        $headers[] = sourceInvoice(['id' => (string) $id, 'terms_name' => 'Net 30']);
    }
    $first = array_slice($headers, 0, 50);
    $last = array_slice($headers, 50);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage($first))->push(sourcePage($first))->push([], 403)
        ->push(sourcePage($last))->push(sourcePage($last))]);

    $this->artisan('milkstool:backfill-invoice-details')->assertFailed();
    expect(Transaction::query()->whereNotNull('invoice_details_synced_at')->count())->toBe(50);
    $this->artisan('milkstool:backfill-invoice-details')->expectsOutput('Invoice details backfill complete: 1 invoices updated.')->assertSuccessful();

    expect(Transaction::query()->whereNotNull('invoice_details_synced_at')->count())->toBe(51);
    Http::assertSentCount(5);
});
