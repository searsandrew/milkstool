<?php

use App\Models\ApiClient;
use App\Models\Company;
use App\Models\CreditMemoApplication;
use App\Models\PaymentApplication;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->company = Company::factory()->create(['id' => 16]);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
});

it('returns invoice payment and credit allocations without replacing source balances', function () {
    $invoice = Transaction::factory()->for($this->company)->create(['id' => 1347, 'type' => 'CustInvc', 'foreign_amount_paid' => '70', 'foreign_amount_unpaid' => '30']);
    $payment = Transaction::factory()->for($this->company)->create(['id' => 1517, 'type' => 'CustPymt', 'number' => 'PAY-1517', 'transaction_date' => '2026-01-02', 'currency_id' => 1]);
    $credit = Transaction::factory()->for($this->company)->create(['id' => 8124, 'type' => 'CustCred']);
    PaymentApplication::factory()->for($payment)->create(['target_netsuite_id' => 1347, 'target_customer_id' => 16, 'target_type' => 'CustInvc', 'foreign_amount' => '40.12345678', 'payment_line_id' => 1]);
    CreditMemoApplication::factory()->for($credit)->create(['target_netsuite_id' => 1347, 'target_customer_id' => 16, 'target_type' => 'CustInvc', 'foreign_amount' => '20', 'credit_line_id' => 0]);
    PaymentApplication::factory()->for($payment)->create(['target_netsuite_id' => 1489, 'target_customer_id' => 16]);

    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonCount(1, 'data.applied_payments')->assertJsonCount(1, 'data.applied_credits')
        ->assertJsonPath('data.applied_payments.0.source_netsuite_id', 1517)
        ->assertJsonPath('data.applied_payments.0.source_number', 'PAY-1517')
        ->assertJsonPath('data.applied_payments.0.source_date', '2026-01-02')
        ->assertJsonPath('data.applied_payments.0.source_currency_id', 1)
        ->assertJsonPath('data.applied_payments.0.source_line_id', 1)
        ->assertJsonPath('data.applied_payments.0.foreign_amount', '40.12345678')
        ->assertJsonPath('data.applied_credits.0.source_netsuite_id', 8124)
        ->assertJsonPath('data.applied_credits.0.source_type', 'CustCred')
        ->assertJsonPath('data.applied_credits.0.source_line_id', 0)
        ->assertJsonPath('data.applied_credits.0.foreign_amount', '20.00000000')
        ->assertJsonPath('data.foreign_amount_paid', '70.00000000')
        ->assertJsonPath('data.foreign_amount_unpaid', '30.00000000')
        ->assertJsonPath('data.settlements_scope', 'same_customer')
        ->assertJsonMissingPath('data.applied_payments.0.raw_payload')
        ->assertJsonMissingPath('data.applied_credits.0.transaction')
        ->assertJsonPath('sync.payments.history_backfilled', false);
    Http::assertNothingSent();
});

it('excludes other customers and unverified target ownership even with broad grants', function (string $applicationClass, string $type) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customers:all']);
    Transaction::factory()->for($this->company)->create(['id' => 1347, 'type' => 'CustInvc']);
    $foreignSource = Transaction::factory()->create(['type' => $type]);
    $ownSource = Transaction::factory()->for($this->company)->create(['type' => $type]);
    $wrongTypeSource = Transaction::factory()->for($this->company)->create(['type' => 'SalesOrd']);
    foreach ([[$foreignSource, 16, 'CustInvc'], [$ownSource, 17, 'CustInvc'], [$ownSource, null, 'CustInvc'], [$ownSource, 16, 'SalesOrd'], [$wrongTypeSource, 16, 'CustInvc']] as $line => [$source, $customer, $targetType]) {
        $applicationClass::factory()->for($source)->create(['target_netsuite_id' => 1347, 'target_customer_id' => $customer, 'target_type' => $targetType, 'target_line_id' => $line]);
    }

    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.applied_payments', [])->assertJsonPath('data.applied_credits', []);
})->with([[PaymentApplication::class, 'CustPymt'], [CreditMemoApplication::class, 'CustCred']]);

it('preserves unknown and signed settlement amounts', function (string $applicationClass, string $type, string $key, ?string $amount) {
    Transaction::factory()->for($this->company)->create(['id' => 1347, 'type' => 'CustInvc']);
    $source = Transaction::factory()->for($this->company)->create(['type' => $type, 'currency_id' => 2]);
    $applicationClass::factory()->for($source)->create(['target_netsuite_id' => 1347, 'target_customer_id' => 16, 'target_type' => 'CustInvc', 'target_currency_id' => 1, 'foreign_amount' => $amount]);

    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.'.$key.'.0.foreign_amount', $amount)
        ->assertJsonPath('data.'.$key.'.0.source_currency_id', 2)
        ->assertJsonPath('data.'.$key.'.0.target_currency_id', 1);
})->with([[PaymentApplication::class, 'CustPymt', 'applied_payments', null], [CreditMemoApplication::class, 'CustCred', 'applied_credits', '-0.00000001']]);

it('omits invoice settlements from other document types and transaction lists', function (string $type) {
    Transaction::factory()->for($this->company)->create(['id' => 1347, 'type' => $type]);

    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonMissingPath('data.applied_payments')->assertJsonMissingPath('data.applied_credits')
        ->assertJsonMissingPath('data.settlements_scope');
    $this->getJson('/api/v1/customers/16/transactions')->assertOk()->assertJsonMissingPath('data.0.applied_payments');
})->with(['SalesOrd', 'CustCred', 'CustPymt']);

it('distinguishes an empty settlement history from one that has not been synced', function () {
    Transaction::factory()->for($this->company)->create(['id' => 1347, 'type' => 'CustInvc']);
    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.applied_payments', [])->assertJsonPath('data.applied_credits', [])
        ->assertJsonPath('sync.payments.history_backfilled', false)
        ->assertJsonPath('sync.credit_memos.history_backfilled', false);
    $this->company->forceFill(['payments_synced_at' => now(), 'payments_backfilled_at' => now(),
        'credit_memos_synced_at' => now(), 'credit_memos_backfilled_at' => now()])->save();

    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.applied_payments', [])->assertJsonPath('data.applied_credits', [])
        ->assertJsonPath('sync.payments.history_backfilled', true)
        ->assertJsonPath('sync.credit_memos.history_backfilled', true);
    $this->getJson('/api/v1/customers/16/transactions?type=CustInvc')->assertOk()
        ->assertJsonMissingPath('data.0.applied_payments')->assertJsonMissingPath('data.0.applied_credits')
        ->assertJsonMissingPath('data.0.settlements_scope');
    Http::assertNothingSent();
});
