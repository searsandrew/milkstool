<?php

use App\Actions\SyncInvoice;
use App\Actions\SyncInvoiceTracking;
use App\Jobs\RefreshRequestedInvoice;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->company = Company::factory()->create(['id' => 16]);
    $this->invoice = Transaction::factory()->for($this->company)->create(['id' => 1347, 'type' => 'CustInvc']);
});

it('requires explicit invoice refresh permission and a company grant', function () {
    $this->postJson('/api/v1/customers/16/invoice-refreshes', ['invoice_id' => 1347])->assertUnauthorized();
    foreach ([['transactions:read', 'customer:16'], ['orders:refresh', 'customer:16'], ['invoices:refresh', 'customer:17'], ['invoices:refresh']] as $abilities) {
        Sanctum::actingAs(ApiClient::factory()->create(), $abilities);
        $this->postJson('/api/v1/customers/16/invoice-refreshes', ['invoice_id' => 1347])->assertForbidden();
    }
    $this->assertDatabaseCount('jobs', 0);
    Http::assertNothingSent();
});

it('deduplicates targeted invoice refreshes on the interactive queue without blocking the request', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['invoices:refresh', 'customer:16']);
    foreach ([1, 2] as $attempt) {
        $this->postJson('/api/v1/customers/16/invoice-refreshes', ['invoice_id' => 1347])->assertAccepted()
            ->assertJsonPath('data.invoice_id', 1347)->assertJsonPath('data.status', 'refresh_requested');
    }
    expect(DB::table('jobs')->sole()->queue)->toBe('interactive-refreshes');
    Http::assertNothingSent();
});

it('rejects foreign wrong type missing and inactive invoices without queuing', function (string $case) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['invoices:refresh', 'customers:all']);
    match ($case) {
        'foreign' => $this->invoice->update(['company_id' => Company::factory()->create()->id]),
        'type' => $this->invoice->update(['type' => 'SalesOrd']),
        'missing' => $this->invoice->delete(),
        'inactive' => $this->company->update(['is_active' => false]),
    };
    $this->postJson('/api/v1/customers/16/invoice-refreshes', ['invoice_id' => 1347])->assertStatus($case === 'inactive' ? 409 : 404);
    $this->assertDatabaseCount('jobs', 0);
})->with(['foreign', 'type', 'missing', 'inactive']);

it('refreshes only the requested invoice and its tracking on the dedicated worker', function () {
    $this->mock(SyncInvoice::class)->shouldReceive('handle')->once()->with(16, 1347)->andReturn($this->invoice);
    $this->mock(SyncInvoiceTracking::class)->shouldReceive('handle')->once()->with(16, [1347])->andReturn(1);
    RefreshRequestedInvoice::dispatch(16, 1347);
    Queue::connection('netsuite')->pop('interactive-refreshes')->fire();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('rechecks ownership when the queued job runs', function () {
    $this->invoice->update(['company_id' => Company::factory()->create()->id]);
    $this->mock(SyncInvoice::class)->shouldNotReceive('handle');
    $this->mock(SyncInvoiceTracking::class)->shouldNotReceive('handle');
    RefreshRequestedInvoice::dispatch(16, 1347);
    Queue::connection('netsuite')->pop('interactive-refreshes')->fire();
    Http::assertNothingSent();
});

it('validates invoice refresh identifiers', function (mixed $id) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['invoices:refresh', 'customer:16']);
    $this->postJson('/api/v1/customers/16/invoice-refreshes', ['invoice_id' => $id])->assertUnprocessable();
    $this->assertDatabaseCount('jobs', 0);
})->with([null, 0, -1, 'abc']);
