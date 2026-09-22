<?php

use App\Models\ApiClient;
use App\Models\Company;
use App\Models\PaymentApplication;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('lists customer payments and exposes only applications to the same customer', function () {
    Http::preventStrayRequests();
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $payment = Transaction::factory()->for($company)->create(['netsuite_id' => 1517, 'type' => 'CustPymt']);
    Transaction::factory()->create(['type' => 'CustPymt']);
    PaymentApplication::factory()->for($payment)->create(['target_customer_id' => 16, 'target_netsuite_id' => 1347, 'foreign_amount' => '60']);
    PaymentApplication::factory()->for($payment)->create(['target_customer_id' => 17, 'target_netsuite_id' => 1489]);
    PaymentApplication::factory()->for($payment)->create(['target_customer_id' => null]);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);

    $this->getJson('/api/v1/customers/16/transactions?type=CustPymt')->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.netsuite_id', 1517)->assertJsonPath('sync.payments.status', 'never_synced');
    $this->getJson('/api/v1/customers/16/transactions/1517')->assertOk()->assertJsonCount(1, 'data.payment_applications')
        ->assertJsonPath('data.payment_applications.0.target_netsuite_id', 1347)
        ->assertJsonPath('data.payment_applications.0.foreign_amount', '60.00000000')
        ->assertJsonMissingPath('data.payment_applications.0.raw_payload')
        ->assertJsonPath('data.payment_applications_scope', 'same_customer');
    $this->getJson('/api/v1/customers/16/transactions?type=CustPymt&outstanding=1')->assertUnprocessable();
    Http::assertNothingSent();
});
