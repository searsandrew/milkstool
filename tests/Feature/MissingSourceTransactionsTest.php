<?php

use App\Actions\FlagMissingSourceTransactions;
use App\Actions\StorePayment;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\PaymentApplication;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('preserves missing records and their financial history with an idempotent staff review flag', function () {
    $company = Company::factory()->create(['id' => 16]);
    $payment = Transaction::factory()->for($company)->create(['id' => 101, 'type' => 'CustPymt']);
    TransactionLine::factory()->for($payment)->create();
    PaymentApplication::factory()->for($payment)->create();
    Http::fake(['https://netsuite.example/*' => Http::response(sourcePage([]))]);

    expect(app(FlagMissingSourceTransactions::class)->handle($company, 'CustPymt', []))->toBe(1);
    expect(app(FlagMissingSourceTransactions::class)->handle($company, 'CustPymt', []))->toBe(0);

    expect(Transaction::find(101))->toBeNull();
    $saved = Transaction::withoutGlobalScope('present_in_netsuite')->findOrFail(101);
    expect($saved->source_missing_at)->not->toBeNull();
    expect($saved->source_review_required_at)->not->toBeNull();
    expect($saved->source_review_reason)->toBe('missing_from_source');
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('transaction_lines', 1);
    $this->assertDatabaseCount('payment_applications', 1);
    Http::assertSentCount(1);
    $this->artisan('milkstool:source-reviews', ['--customer' => 16])->expectsOutput('1 records require staff review.')->assertSuccessful();
});

it('does not flag records when verification fails or still finds the same customer record', function (string $failure) {
    $company = Company::factory()->create(['id' => 16]);
    $order = Transaction::factory()->for($company)->create(['id' => 101, 'type' => 'SalesOrd']);
    Http::fake(['https://netsuite.example/*' => match ($failure) {
        'network' => Http::failedConnection(),
        'partial' => Http::response(sourcePage([], true)),
        default => Http::response(sourcePage([['id' => '101', 'customer_id' => '16', 'type' => 'SalesOrd']])),
    }]);

    expect(fn () => app(FlagMissingSourceTransactions::class)->handle($company, 'SalesOrd', []))->toThrow($failure === 'network' ? ConnectionException::class : RuntimeException::class);

    expect($order->fresh()->source_missing_at)->toBeNull();
    expect($order->fresh()->source_review_required_at)->toBeNull();
})->with(['network', 'partial', 'still present']);

it('scopes absence detection to one customer and one transaction type', function () {
    $company = Company::factory()->create(['id' => 16]);
    Transaction::factory()->for($company)->create(['id' => 101, 'type' => 'SalesOrd']);
    $invoice = Transaction::factory()->for($company)->create(['id' => 102, 'type' => 'CustInvc']);
    $other = Transaction::factory()->create(['id' => 103, 'type' => 'SalesOrd']);
    Http::fake(['https://netsuite.example/*' => Http::response(sourcePage([]))]);

    app(FlagMissingSourceTransactions::class)->handle($company, 'SalesOrd', []);

    expect($invoice->fresh()->source_missing_at)->toBeNull();
    expect($other->fresh()->source_missing_at)->toBeNull();
});

it('flags an identity change without reassigning the preserved transaction', function () {
    $company = Company::factory()->create(['id' => 16]);
    Transaction::factory()->for($company)->create(['id' => 101, 'type' => 'SalesOrd']);
    Http::fake(['https://netsuite.example/*' => Http::response(sourcePage([['id' => '101', 'customer_id' => '99', 'type' => 'SalesOrd']]))]);

    app(FlagMissingSourceTransactions::class)->handle($company, 'SalesOrd', []);

    $saved = Transaction::withoutGlobalScope('present_in_netsuite')->findOrFail(101);
    expect($saved->company_id)->toBe(16);
    expect($saved->source_review_reason)->toBe('source_identity_changed');
});

it('finishes a complete empty sales scan while preserving the missing order for review', function () {
    $company = Company::factory()->create(['id' => 16]);
    Transaction::factory()->for($company)->create(['id' => 101, 'type' => 'SalesOrd']);
    Http::fake(['https://netsuite.example/*' => Http::sequence()->push(sourcePage([sourceCustomer()]))
        ->push(sourcePage([]))->push(sourcePage([]))]);

    $this->artisan('milkstool:sync-sales-orders', ['customer' => 16])->assertSuccessful();

    expect($company->fresh()->sales_orders_synced_at)->not->toBeNull();
    expect(Transaction::withoutGlobalScope('present_in_netsuite')->findOrFail(101)->source_missing_at)->not->toBeNull();
    $this->assertDatabaseCount('transactions', 1);
});

it('finishes an empty payment scan without counting preserved lines or applications in reconciliation', function () {
    $company = Company::factory()->create(['id' => 16]);
    $payment = Transaction::factory()->for($company)->create(['id' => 101, 'type' => 'CustPymt']);
    TransactionLine::factory()->for($payment)->create();
    PaymentApplication::factory()->for($payment)->create();
    Http::fake(['https://netsuite.example/*' => Http::sequence()->push(sourcePage([sourceCustomer()]))
        ->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([]))]);

    $this->artisan('milkstool:sync-payments', ['customer' => 16])->assertSuccessful();

    expect($company->fresh()->payments_synced_at)->not->toBeNull();
    $this->assertDatabaseCount('transaction_lines', 1);
    $this->assertDatabaseCount('payment_applications', 1);
});

it('hides preserved records from customer list detail and invoice payment applications', function () {
    $company = Company::factory()->create(['id' => 16]);
    $invoice = Transaction::factory()->for($company)->create(['id' => 102, 'type' => 'CustInvc']);
    $payment = Transaction::factory()->for($company)->create(['id' => 101, 'type' => 'CustPymt']);
    PaymentApplication::factory()->for($payment)->create(['target_netsuite_id' => 102, 'target_customer_id' => 16]);
    Http::fake(['https://netsuite.example/*' => Http::response(sourcePage([]))]);
    app(FlagMissingSourceTransactions::class)->handle($company, 'CustPymt', []);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);

    $this->getJson('/api/v1/customers/16/transactions?type=CustPymt')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/customers/16/transactions/101')->assertNotFound();
    $this->getJson('/api/v1/customers/16/transactions/102')->assertOk()->assertJsonPath('data.applied_payments', [])
        ->assertJsonMissingPath('data.source_review_required_at');
});

it('restores a reappearing payment using its original row and keeps the staff review pending', function () {
    $company = Company::factory()->create(['id' => 16]);
    $payment = Transaction::factory()->for($company)->create(['id' => 1517, 'type' => 'CustPymt']);
    Http::fake(['https://netsuite.example/*' => Http::response(sourcePage([]))]);
    app(FlagMissingSourceTransactions::class)->handle($company, 'CustPymt', []);
    $header = sourceInvoice(['id' => '1517', 'type' => 'CustPymt', 'number' => 'PYMT-RETURNED']);

    $restored = app(StorePayment::class)->handle($company, $header, [], []);

    expect(Transaction::find(1517))->not->toBeNull();
    expect($restored->source_missing_at)->toBeNull();
    expect($restored->source_review_required_at)->not->toBeNull();
    expect($restored->source_review_context)->toHaveKey('last_reappeared_at');
    $this->assertDatabaseCount('transactions', 1);
});
