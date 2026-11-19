<?php

use App\Actions\SyncCreditMemo;
use App\Jobs\RefreshRequestedCreditMemo;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->company = Company::factory()->create(['id' => 16]);
    $this->creditMemo = Transaction::factory()->for($this->company)->create(['id' => 1347, 'type' => 'CustCred']);
});

it('requires explicit credit memo refresh permission and a company grant', function () {
    $this->postJson('/api/v1/customers/16/credit-memo-refreshes', ['credit_memo_id' => 1347])->assertUnauthorized();
    foreach ([['transactions:read', 'customer:16'], ['orders:refresh', 'customer:16'], ['credit-memos:refresh', 'customer:17'], ['credit-memos:refresh']] as $abilities) {
        Sanctum::actingAs(ApiClient::factory()->create(), $abilities);
        $this->postJson('/api/v1/customers/16/credit-memo-refreshes', ['credit_memo_id' => 1347])->assertForbidden();
    }
    $this->assertDatabaseCount('jobs', 0);
    Http::assertNothingSent();
});

it('deduplicates targeted credit memo refreshes on the interactive queue without blocking the request', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['credit-memos:refresh', 'customer:16']);
    foreach ([1, 2] as $attempt) {
        $this->postJson('/api/v1/customers/16/credit-memo-refreshes', ['credit_memo_id' => 1347])->assertAccepted()
            ->assertJsonPath('data.credit_memo_id', 1347)->assertJsonPath('data.status', 'refresh_requested');
    }
    expect(DB::table('jobs')->sole()->queue)->toBe('interactive-refreshes');
    Http::assertNothingSent();
});

it('rejects foreign wrong type missing and inactive credit memos without queuing', function (string $case) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['credit-memos:refresh', 'customers:all']);
    match ($case) {
        'foreign' => $this->creditMemo->update(['company_id' => Company::factory()->create()->id]),
        'type' => $this->creditMemo->update(['type' => 'SalesOrd']),
        'missing' => $this->creditMemo->delete(),
        'inactive' => $this->company->update(['is_active' => false]),
    };
    $this->postJson('/api/v1/customers/16/credit-memo-refreshes', ['credit_memo_id' => 1347])->assertStatus($case === 'inactive' ? 409 : 404);
    $this->assertDatabaseCount('jobs', 0);
})->with(['foreign', 'type', 'missing', 'inactive']);

it('refreshes only the requested credit memo and its applications on the dedicated worker', function () {
    $this->mock(SyncCreditMemo::class)->shouldReceive('handle')->once()->with(16, 1347)->andReturn($this->creditMemo);
    RefreshRequestedCreditMemo::dispatch(16, 1347);
    Queue::connection('netsuite')->pop('interactive-refreshes')->fire();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('rechecks ownership when the queued job runs', function () {
    $this->creditMemo->update(['company_id' => Company::factory()->create()->id]);
    $this->mock(SyncCreditMemo::class)->shouldNotReceive('handle');
    RefreshRequestedCreditMemo::dispatch(16, 1347);
    Queue::connection('netsuite')->pop('interactive-refreshes')->fire();
    Http::assertNothingSent();
});

it('validates credit memo refresh identifiers', function (mixed $id) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['credit-memos:refresh', 'customer:16']);
    $this->postJson('/api/v1/customers/16/credit-memo-refreshes', ['credit_memo_id' => $id])->assertUnprocessable();
    $this->assertDatabaseCount('jobs', 0);
})->with([null, 0, -1, 'abc']);

it('issues the new refresh ability only when explicitly requested with a customer grant', function () {
    $this->artisan('milkstool:token:issue', ['client' => 'credit-refresh-test', '--credit-memos' => true])->assertFailed();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->artisan('milkstool:token:issue', ['client' => 'credit-refresh-test', '--credit-memos' => true, '--customer' => ['16']])->assertSuccessful();
    $token = PersonalAccessToken::sole();
    expect($token->can('credit-memos:refresh'))->toBeTrue()
        ->and($token->can('customer:16'))->toBeTrue()
        ->and($token->can('invoices:refresh'))->toBeFalse()
        ->and($token->can('orders:refresh'))->toBeFalse();
});
