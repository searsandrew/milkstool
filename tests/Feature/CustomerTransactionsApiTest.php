<?php

use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->company = Company::factory()->create(['netsuite_id' => 16]);
});

it('requires authentication on every customer endpoint', function (string $path) {
    $this->getJson('/api/v1/customers/16/'.$path)->assertUnauthorized();
})->with(['transactions', 'transactions/1347', 'invoice-summary']);

it('requires both read permission and the customer grant', function (array $abilities) {
    Sanctum::actingAs(ApiClient::factory()->create(), $abilities);
    foreach (['transactions', 'transactions/1347', 'invoice-summary'] as $path) {
        $this->getJson('/api/v1/customers/16/'.$path)->assertForbidden();
    }
})->with(['status only' => [['status:read']], 'no customer' => [['transactions:read']], 'wrong customer' => [['transactions:read', 'customer:17']], 'no read' => [['customer:16']]]);

it('paginates only the requested customer and excludes private payloads', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    Transaction::factory()->for($this->company)->create(['netsuite_id' => 100, 'transaction_date' => '2026-01-01', 'raw_payload' => ['secret' => 'private source data']]);
    Transaction::factory()->for($this->company)->create(['netsuite_id' => 101, 'transaction_date' => '2026-01-01']);
    Transaction::factory()->create(['netsuite_id' => 999]);

    $response = $this->getJson('/api/v1/customers/16/transactions?per_page=1');

    $response->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.netsuite_id', 101)
        ->assertJsonMissingPath('data.0.raw_payload')->assertJsonMissingPath('data.0.lines')
        ->assertJsonPath('sync.invoices.status', 'never_synced');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->getJson('/api/v1/customers/16/transactions?per_page=1&page=2')->assertJsonPath('data.0.netsuite_id', 100);
    Http::assertNothingSent();
});

it('returns decimal strings and ordered lines only for the owning customer', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customers:all']);
    $invoice = Transaction::factory()->for($this->company)->create(['netsuite_id' => 1347, 'type' => 'CustInvc', 'foreign_amount_unpaid' => '123.12345678']);
    TransactionLine::factory()->for($invoice)->create(['netsuite_line_id' => 2, 'quantity' => null, 'raw_payload' => ['private' => 'hidden']]);
    TransactionLine::factory()->for($invoice)->create(['netsuite_line_id' => 0, 'quantity' => '-2.50000000']);
    Company::factory()->create(['netsuite_id' => 17]);

    $this->getJson('/api/v1/customers/16/transactions/1347')->assertOk()
        ->assertJsonPath('data.foreign_amount_unpaid', '123.12345678')
        ->assertJsonPath('data.lines.0.netsuite_line_id', 0)->assertJsonPath('data.lines.0.quantity', '-2.50000000')
        ->assertJsonPath('data.lines.1.quantity', null)->assertJsonMissingPath('data.lines.1.raw_payload');
    $this->getJson('/api/v1/customers/17/transactions/1347')->assertNotFound();
    $this->getJson('/api/v1/customers/16/transactions/9999')->assertNotFound();
});

it('filters open invoices using source unpaid amounts and date bounds', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    foreach ([['CustInvc', '10', '2026-01-02'], ['CustInvc', '0', '2026-01-02'], ['CustInvc', null, '2026-01-02'], ['CustCred', '10', '2026-01-02'], ['CustInvc', '10', '2025-01-01']] as [$type, $unpaid, $date]) {
        Transaction::factory()->for($this->company)->create(['type' => $type, 'foreign_amount_unpaid' => $unpaid, 'transaction_date' => $date]);
    }
    $this->getJson('/api/v1/customers/16/transactions?outstanding=1&from=2026-01-01&to=2026-01-31')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.type', 'CustInvc');
    $this->getJson('/api/v1/customers/16/transactions?type=CustCred')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/customers/16/transactions?to=2025-12-31')->assertOk()->assertJsonPath('meta.total', 1);
});

it('rejects invalid or contradictory filters', function (string $query) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/transactions?'.$query)->assertUnprocessable();
})->with(['per_page=101', 'page=0', 'type=Unknown', 'outstanding=abc', 'outstanding=1&type=CustCred', 'from=2026-02-01&to=2026-01-01', 'from=not-a-date']);

it('groups known invoice outstanding amounts by currency without counting credits or unknowns as known zero', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    foreach ([['CustInvc', '0.1', 1], ['CustInvc', '0.2', 1], ['CustInvc', null, 1], ['CustInvc', '7', 2], ['CustCred', '100', 1]] as [$type, $unpaid, $currency]) {
        Transaction::factory()->for($this->company)->create(['type' => $type, 'foreign_amount_unpaid' => $unpaid, 'currency_id' => $currency]);
    }
    Transaction::factory()->create(['type' => 'CustInvc', 'foreign_amount_unpaid' => '900']);

    $this->getJson('/api/v1/customers/16/invoice-summary')->assertOk()
        ->assertJsonPath('data.scope', 'outstanding_invoices')
        ->assertJsonPath('data.currencies.0.known_outstanding_amount', '0.30000000')
        ->assertJsonPath('data.currencies.0.unknown_unpaid_count', 1)
        ->assertJsonPath('data.currencies.0.outstanding_invoice_count', 2)
        ->assertJsonPath('data.currencies.1.known_outstanding_amount', '7.00000000')
        ->assertJsonMissingPath('data.account_balance');
    Http::assertNothingSent();
});

it('distinguishes verified empty history from an unsynced empty history', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/invoice-summary')->assertJsonPath('data.currencies', [])
        ->assertJsonPath('sync.invoices.status', 'never_synced')->assertJsonPath('sync.invoices.history_backfilled', false);
    $this->company->forceFill(['invoices_synced_at' => now(), 'invoices_backfilled_at' => now(), 'invoices_next_sync_at' => now()->addHours(6)])->save();
    $this->getJson('/api/v1/customers/16/invoice-summary')->assertJsonPath('data.currencies', [])
        ->assertJsonPath('sync.invoices.status', 'current')->assertJsonPath('sync.invoices.history_backfilled', true);
    $this->company->forceFill(['invoices_sync_error' => 'Internal private failure'])->save();
    $this->getJson('/api/v1/customers/16/invoice-summary')->assertJsonPath('sync.invoices.status', 'failed')
        ->assertDontSee('Internal private failure');
});

it('issues explicit customer grants without broadening existing status tokens', function () {
    Artisan::call('milkstool:token:issue', ['client' => 'portal', '--customer' => ['16']]);
    $token = ApiClient::query()->where('name', 'portal')->firstOrFail()->tokens()->sole();
    expect($token->abilities)->toBe(['status:read', 'transactions:read', 'customer:16']);
    Artisan::call('milkstool:token:issue', ['client' => 'admin', '--customer' => ['all']]);
    expect(ApiClient::query()->where('name', 'admin')->firstOrFail()->tokens()->sole()->abilities)->toContain('customers:all');
});

it('rejects malformed token customer grants', function (string $customer) {
    $this->artisan('milkstool:token:issue', ['client' => 'portal', '--customer' => [$customer]])->assertFailed();
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['0', '-1', '*', '16 OR 1=1']);

it('uses real bearer token grants and rejects a revoked token', function () {
    $token = ApiClient::factory()->create()->createToken('portal', ['transactions:read', 'customer:16'], now()->addDay());
    $this->withToken($token->plainTextToken)->getJson('/api/v1/customers/16/transactions')->assertOk();
    Company::factory()->create(['netsuite_id' => 17]);
    $this->withToken($token->plainTextToken)->getJson('/api/v1/customers/17/transactions')->assertForbidden();
    $token->accessToken->delete();
    app('auth')->forgetGuards();
    $this->withToken($token->plainTextToken)->getJson('/api/v1/customers/16/transactions')->assertUnauthorized();
});

it('marks old and interrupted invoice histories as requiring refresh', function (string $state) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->company->forceFill(['invoices_synced_at' => now()->subDay(), 'invoices_backfilled_at' => now()->subDay(),
        'invoices_next_sync_at' => now()->subHour(),
        'invoices_sync_started_at' => $state === 'unfinished_attempt' ? now() : null])->save();
    $this->getJson('/api/v1/customers/16/invoice-summary')->assertOk()->assertJsonPath('sync.invoices.status', $state);
})->with(['stale', 'unfinished_attempt']);
