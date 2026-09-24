<?php

use App\Actions\SyncCreditMemos;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\CreditMemoApplication;
use App\Models\Transaction;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->company = Company::factory()->create(['id' => 16]);
});

/** @return list<array<string, mixed>> */
function creditApplications(): array
{
    return [
        ['source_id' => '8124', 'source_line_id' => '0', 'target_netsuite_id' => '7177', 'target_line_id' => '0',
            'target_customer_id' => '16', 'target_currency_id' => '1', 'target_type' => 'CustInvc', 'foreign_amount' => '60'],
        ['source_id' => '8124', 'source_line_id' => '0', 'target_netsuite_id' => '7178', 'target_line_id' => '0',
            'target_customer_id' => '16', 'target_currency_id' => '1', 'target_type' => 'CustInvc', 'foreign_amount' => '40'],
    ];
}

/** @param list<array<string, mixed>> $applications
 * @param  list<array<string, mixed>>|null  $verification
 */
function fakeCreditApplicationSync(array $applications, ?array $verification = null, string $controlAmount = '100'): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $credit = sourceInvoice(['id' => '8124', 'type' => 'CustCred', 'total' => '-100', 'foreign_total' => '-100',
        'foreign_amount_paid' => null, 'foreign_amount_unpaid' => null]);
    $line = sourceInvoiceLine(['transaction_id' => '8124', 'quantity' => '1', 'amount' => '100']);
    $totals = $applications === [] ? [] : [['currency_id' => '1', 'application_count' => (string) count($applications),
        'application_amount_count' => (string) count(array_filter($applications, fn ($row) => isset($row['foreign_amount']))),
        'application_amount' => $controlAmount]];
    Http::fake(['https://netsuite.example/*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([$credit]))->push(sourcePage([$line]))
        ->push(sourcePage($applications))->push(sourcePage($verification ?? $applications))->push(sourcePage([$credit]))
        ->push(sourcePage([['currency_id' => '1', 'credit_memo_count' => '1', 'paid_count' => '0', 'unpaid_count' => '0',
            'foreign_amount_paid' => '0', 'foreign_amount_unpaid' => '0', 'total' => '-100', 'foreign_total' => '-100']]))
        ->push(sourcePage([['currency_id' => '1', 'line_count' => '1', 'quantity_count' => '1', 'amount_count' => '1',
            'quantity' => '1', 'amount' => '100', 'detail_amount' => '100']]))->push(sourcePage($totals))]);
}

it('imports credits applied across invoices and reconciles all sixteen metrics', function () {
    fakeCreditApplicationSync(creditApplications());
    $result = app(SyncCreditMemos::class)->handle(16);
    expect($result['reconciliation'])->toHaveCount(16);
    expect(collect($result['reconciliation'])->every('matches'))->toBeTrue();
    $this->assertDatabaseCount('credit_memo_applications', 2);
    $this->assertDatabaseCount('payment_applications', 0);
    expect(CreditMemoApplication::query()->orderBy('target_netsuite_id')->first())->toMatchArray(['credit_line_id' => 0, 'foreign_amount' => '60.00000000']);
    expect(Transaction::query()->sole()->foreign_total)->toBe('-100.00000000');
    expect($this->company->refresh()->credit_memos_backfilled_at)->not->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request['q'], "payment.type = 'CustCred'") && str_contains($request['q'], "l.linktype = 'Payment'"));
});

it('refreshes and removes applications even when the credit header is unchanged', function () {
    fakeCreditApplicationSync(creditApplications());
    app(SyncCreditMemos::class)->handle(16);
    fakeCreditApplicationSync([array_replace(creditApplications()[0], ['foreign_amount' => '100'])]);
    app(SyncCreditMemos::class)->handle(16);
    $this->assertDatabaseCount('credit_memo_applications', 1);
    expect(CreditMemoApplication::query()->sole()->foreign_amount)->toBe('100.00000000');
    fakeCreditApplicationSync([]);
    app(SyncCreditMemos::class)->handle(16);
    $this->assertDatabaseCount('credit_memo_applications', 0);
    $this->assertDatabaseCount('transactions', 1);
});

it('retains old applications when source verification changes', function () {
    $credit = Transaction::factory()->for($this->company)->create(['id' => 8124, 'type' => 'CustCred']);
    $existing = CreditMemoApplication::factory()->for($credit)->create();
    fakeCreditApplicationSync(creditApplications(), []);
    $this->artisan('milkstool:sync-credit-memos', ['customer' => 16])->assertFailed();
    $this->assertModelExists($existing);
    expect($this->company->refresh()->credit_memos_synced_at)->toBeNull();
});

it('does not advance freshness when credit application reconciliation differs', function () {
    $this->company->forceFill(['credit_memos_synced_at' => '2026-09-01 12:00:00'])->save();
    fakeCreditApplicationSync(creditApplications(), controlAmount: '99');
    $this->artisan('milkstool:sync-credit-memos', ['customer' => 16])->assertFailed();
    expect($this->company->refresh()->credit_memos_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($this->company->credit_memos_backfilled_at)->toBeNull();
});

it('preserves unknown and signed application amounts', function (?string $amount, string $total) {
    fakeCreditApplicationSync([array_replace(creditApplications()[0], ['foreign_amount' => $amount, 'target_customer_id' => null])], controlAmount: $total);
    $this->artisan('milkstool:sync-credit-memos', ['customer' => 16])->assertSuccessful();
    expect(CreditMemoApplication::query()->sole()->foreign_amount)->toBe($amount);
    expect(CreditMemoApplication::query()->sole()->target_customer_id)->toBeNull();
})->with([[null, '0'], ['-0.00000001', '-0.00000001']]);

it('exposes credit applications only to the same customer and keeps them distinct from payments', function () {
    $credit = Transaction::factory()->for($this->company)->create(['id' => 8124, 'type' => 'CustCred']);
    CreditMemoApplication::factory()->for($credit)->create(['target_netsuite_id' => 7177, 'target_customer_id' => 16]);
    CreditMemoApplication::factory()->for($credit)->create(['target_customer_id' => 17]);
    CreditMemoApplication::factory()->for($credit)->create(['target_customer_id' => null]);
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:16']);
    $this->getJson('/api/v1/customers/16/transactions/8124')->assertOk()
        ->assertJsonCount(1, 'data.credit_memo_applications')->assertJsonPath('data.credit_memo_applications.0.target_netsuite_id', 7177)
        ->assertJsonPath('data.credit_memo_applications_scope', 'same_customer')
        ->assertJsonMissingPath('data.credit_memo_applications.0.raw_payload');
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customer:17']);
    $this->getJson('/api/v1/customers/16/transactions/8124')->assertForbidden();
    Http::assertNothingSent();
});
