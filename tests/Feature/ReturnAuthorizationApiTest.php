<?php

use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('requires authentication customer grants and transaction read permission for RMAs', function () {
    Company::factory()->create(['id' => 16]);
    Http::preventStrayRequests();

    $this->getJson('/api/v1/customers/16/transactions?type=RtnAuth')->assertUnauthorized();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:17']);
    $this->getJson('/api/v1/customers/16/transactions?type=RtnAuth')->assertForbidden();
    Sanctum::actingAs(ApiClient::factory()->create(), ['customer:16']);
    $this->getJson('/api/v1/customers/16/transactions?type=RtnAuth')->assertForbidden();
    Http::assertNothingSent();
});

it('returns cached RMA status decimal amounts and per-line source references without exposing raw payloads', function () {
    Http::preventStrayRequests();
    $company = Company::factory()->create(['id' => 16]);
    $rma = Transaction::factory()->for($company)->create(['id' => 9001, 'type' => 'RtnAuth', 'status' => 'B', 'external_id' => 'saturn-v-return-example']);
    Transaction::factory()->for($company)->create(['type' => 'CustInvc']);
    Transaction::factory()->create(['type' => 'RtnAuth', 'external_id' => 'saturn-v-return-example']);
    TransactionLine::factory()->for($rma)->create(['netsuite_line_id' => 1, 'source_transaction_id' => 1347, 'source_transaction_line_id' => 3,
        'quantity' => '1.25000000', 'raw_payload' => ['quantity_received' => '0.50000000', 'quantity_billed' => '0.00000000', 'is_closed' => false, 'private' => 'hidden']]);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customers:all']);

    $this->getJson('/api/v1/customers/16/transactions?type=RtnAuth&external_id=saturn-v-return-example')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.netsuite_id', 9001)
        ->assertJsonPath('data.0.status', 'B')->assertJsonMissingPath('data.0.raw_payload')
        ->assertJsonPath('sync.return_authorizations.status', 'never_synced');
    $this->getJson('/api/v1/customers/16/transactions/9001')->assertOk()
        ->assertJsonPath('data.line_scope', 'return_authorization_items')
        ->assertJsonPath('data.lines.0.source_transaction_id', 1347)->assertJsonPath('data.lines.0.source_transaction_line_id', 3)
        ->assertJsonPath('data.lines.0.quantity', '1.25000000')->assertJsonPath('data.lines.0.quantity_received', '0.50000000')
        ->assertJsonPath('data.lines.0.is_closed', false)->assertJsonMissingPath('data.lines.0.raw_payload');
    $other = Company::factory()->create();
    $this->getJson('/api/v1/customers/'.$other->id.'/transactions/9001')->assertNotFound();
    Http::assertNothingSent();
});

it('includes linked references and excludes duplicates without crossing customer boundaries', function () {
    $company = Company::factory()->create(['id' => 16]);
    Transaction::factory()->for($company)->create(['id' => 9001, 'type' => 'RtnAuth', 'external_id' => 'request-one']);
    Transaction::factory()->for($company)->create(['id' => 9002, 'type' => 'RtnAuth', 'external_id' => 'request-two']);
    Transaction::factory()->for($company)->create(['id' => 9004, 'type' => 'RtnAuth', 'external_id' => null]);
    Transaction::factory()->create(['id' => 9003, 'type' => 'RtnAuth', 'external_id' => 'request-two']);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);

    $this->getJson('/api/v1/customers/16/transactions?'.http_build_query(['type' => 'RtnAuth', 'ids' => [9001], 'external_ids' => ['request-two']]))
        ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonMissing(['netsuite_id' => 9003]);
    $this->getJson('/api/v1/customers/16/transactions?'.http_build_query(['type' => 'RtnAuth', 'exclude_ids' => [9001], 'exclude_external_ids' => ['request-two']]))
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.netsuite_id', 9004);
});

it('rejects malformed transaction reference filters', function () {
    Company::factory()->create(['id' => 16]);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);

    $this->getJson('/api/v1/customers/16/transactions?'.http_build_query(['exclude_ids' => ['1 OR 1=1']]))->assertUnprocessable();
    $this->getJson('/api/v1/customers/16/transactions?'.http_build_query(['external_ids' => [['not-a-string']]]))->assertUnprocessable();
});
