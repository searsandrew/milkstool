<?php

use App\Actions\SyncCreditMemos;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\NetSuite\CreditMemoSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceCreditMemo(array $overrides = []): array
{
    return sourceInvoice(array_replace(['type' => 'CustCred', 'total' => '-25.12345678', 'foreign_total' => '-25.12345678'], $overrides));
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceCreditMemoLine(array $overrides = []): array
{
    return sourceInvoiceLine(array_replace(['quantity' => '2.5', 'amount' => '25.12345678'], $overrides));
}

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function creditMemoHeaderTotals(array $overrides = []): array
{
    return array_replace(['currency_id' => '1', 'credit_memo_count' => '1', 'paid_count' => '1', 'unpaid_count' => '1',
        'foreign_amount_paid' => '20', 'foreign_amount_unpaid' => '5.12345678', 'total' => '-25.12345678', 'foreign_total' => '-25.12345678'], $overrides);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function creditMemoLineTotals(array $overrides = []): array
{
    return array_replace(['currency_id' => '1', 'line_count' => '1', 'quantity_count' => '1', 'amount_count' => '1',
        'quantity' => '2.5', 'amount' => '25.12345678', 'detail_amount' => '25.12345678'], $overrides);
}

it('batches creditMemos and reconciles currencies without mixing sales orders or other customers', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    Transaction::factory()->for($company)->create();
    Transaction::factory()->create(['type' => 'CustCred']);
    $second = sourceCreditMemo(['id' => '1348', 'currency_id' => '2']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceCreditMemo(), $second]))
        ->push(sourcePage([sourceCreditMemoLine(), sourceCreditMemoLine(['transaction_id' => '1348'])]))
        ->push(sourcePage([sourceCreditMemo(), $second]))
        ->push(sourcePage([creditMemoHeaderTotals(), creditMemoHeaderTotals(['currency_id' => '2'])]))
        ->push(sourcePage([creditMemoLineTotals(), creditMemoLineTotals(['currency_id' => '2'])])))]);

    $result = app(SyncCreditMemos::class)->handle(16);

    expect($result['creditMemos'])->toBe(2);
    expect($result['lines'])->toBe(2);
    expect($result['reconciliation'])->toHaveCount(32);
    expect(collect($result['reconciliation'])->every('matches'))->toBeTrue();
    expect($company->refresh()->credit_memos_synced_at)->not->toBeNull();
    expect($company->sales_orders_synced_at)->toBeNull();
    Http::assertSentCount(9);
});

it('refreshes unchanged modification timestamps and payment snapshots without duplicating creditMemos', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $existing = Transaction::factory()->for($company)->create(['netsuite_id' => 1347, 'type' => 'CustCred',
        'netsuite_updated_at' => '2026-09-01 12:00:00', 'foreign_amount_paid' => '0', 'foreign_amount_unpaid' => '25.12345678']);
    TransactionLine::factory()->for($existing)->create(['netsuite_line_id' => 9]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceCreditMemo()]))->push(sourcePage([sourceCreditMemoLine()]))
        ->push(sourcePage([sourceCreditMemo()]))->push(sourcePage([creditMemoHeaderTotals()]))->push(sourcePage([creditMemoLineTotals()])))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertSuccessful();

    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('transaction_lines', 1);
    expect($existing->refresh()->foreign_amount_paid)->toBe('20.00000000');
    expect($existing->lines()->sole()->netsuite_line_id)->toBe(1);
    Http::assertSentCount(9);
});

it('preserves last success when reconciliation fails', function (array $header, array $lines) {
    $company = Company::factory()->create(['netsuite_id' => 16, 'credit_memos_synced_at' => '2026-09-01 12:00:00']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceCreditMemo()]))->push(sourcePage([sourceCreditMemoLine()]))
        ->push(sourcePage([sourceCreditMemo()]))->push(sourcePage([creditMemoHeaderTotals($header)]))->push(sourcePage([creditMemoLineTotals($lines)])))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertFailed();

    expect($company->refresh()->credit_memos_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($company->credit_memos_sync_error)->not->toBeNull();
    expect($company->credit_memos_backfilled_at)->toBeNull();
    expect(Cache::lock('netsuite-credit-memos:16', 600)->get())->toBeTrue();
    Http::assertSentCount(9);
})->with([
    'payment changed' => [['foreign_amount_unpaid' => '0'], []],
    'null count differs' => [['paid_count' => '0'], []],
    'line amount differs' => [[], ['detail_amount' => '-99']],
]);

it('keeps missing source creditMemos and rejects a false successful sync', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $missing = Transaction::factory()->for($company)->create(['type' => 'CustCred']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([])))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertFailed();

    $this->assertModelExists($missing);
    expect($company->refresh()->credit_memos_synced_at)->toBeNull();
});

it('records a reconciled empty history only after validating the customer', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([])))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertSuccessful();

    expect($company->refresh()->credit_memos_synced_at)->not->toBeNull();
    Http::assertSentCount(5);
});

it('does not replace old lines when a batch line page fails', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $creditMemo = Transaction::factory()->for($company)->create(['netsuite_id' => 1347, 'type' => 'CustCred']);
    $line = TransactionLine::factory()->for($creditMemo)->create();
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceCreditMemo()]))
        ->push(sourcePage([sourceCreditMemoLine()], true))->push([], 503))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertFailed();

    $this->assertModelExists($line);
    expect($creditMemo->refresh()->total)->toBe('100.00000000');
    expect($company->refresh()->credit_memos_synced_at)->toBeNull();
});

it('rejects changed or missing verification headers before saving a batch', function (array $verification) {
    Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceCreditMemo()]))->push(sourcePage([sourceCreditMemoLine()]))
        ->push(sourcePage($verification)))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
})->with(['missing' => [[]], 'payment changed' => [[sourceCreditMemo(['foreign_amount_unpaid' => '0'])]]]);

it('paginates customer creditMemos and refuses duplicate header pages', function (bool $duplicate) {
    $second = sourceCreditMemo(['id' => $duplicate ? '1347' : '1348']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCreditMemo()], true))->push(sourcePage([$second])))]);

    if ($duplicate) {
        expect(fn () => iterator_to_array(app(CreditMemoSource::class)->creditMemos(16)))->toThrow(RuntimeException::class);
    } else {
        expect(iterator_to_array(app(CreditMemoSource::class)->creditMemos(16)))->toHaveCount(2);
    }
    Http::assertSent(fn ($request) => str_contains($request['q'], 'id > 1347 ORDER BY id'));
})->with([true, false]);

it('rejects truncated reconciliation totals', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))
        ->push(sourcePage([creditMemoHeaderTotals()], true))->push(sourcePage([])))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertFailed();

    expect($company->refresh()->credit_memos_synced_at)->toBeNull();
});

it('shares the customer credit memo lock and does not change sync state on contention', function () {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    Cache::lock('netsuite-credit-memos:16', 600)->get();

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertFailed();

    expect($company->refresh()->credit_memos_sync_started_at)->toBeNull();
    Http::assertNothingSent();
});

it('keeps completed batches but leaves freshness unchanged when a later page fails', function () {
    $company = Company::factory()->create(['netsuite_id' => 16, 'credit_memos_synced_at' => '2026-09-01 12:00:00']);
    $creditMemos = array_map(fn (int $id): array => sourceCreditMemo(['id' => (string) $id]), range(1347, 1396));
    $lines = array_map(fn (int $id): array => sourceCreditMemoLine(['transaction_id' => (string) $id]), range(1347, 1396));
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage($creditMemos, true))
        ->push(sourcePage($lines))->push(sourcePage($creditMemos))->push([], 503))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertFailed();

    $this->assertDatabaseCount('transactions', 50);
    $this->assertDatabaseCount('transaction_lines', 50);
    expect($company->refresh()->credit_memos_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($company->credit_memos_sync_error)->not->toBeNull();
    expect($company->credit_memos_backfilled_at)->toBeNull();
    Http::assertSentCount(7);
});

it('reconciles unknown paid amounts without treating null as a known zero', function () {
    Company::factory()->create(['netsuite_id' => 16]);
    $creditMemo = sourceCreditMemo(['foreign_amount_paid' => null, 'foreign_amount_unpaid' => null]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([$creditMemo]))->push(sourcePage([sourceCreditMemoLine()]))
        ->push(sourcePage([$creditMemo]))
        ->push(sourcePage([creditMemoHeaderTotals(['paid_count' => '0', 'unpaid_count' => '0', 'foreign_amount_paid' => '0', 'foreign_amount_unpaid' => '0'])]))
        ->push(sourcePage([creditMemoLineTotals()])))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertSuccessful();

    expect(Transaction::query()->sole()->foreign_amount_paid)->toBeNull();
    expect(Transaction::query()->sole()->foreign_amount_unpaid)->toBeNull();
});

it('rejects invalid or unregistered customers without contacting NetSuite', function (string $customer) {
    $this->artisan('milkstool:sync-credit-memos', ['customer' => $customer])->assertFailed();
    Http::assertNothingSent();
})->with(['0', '16 OR 1=1', '16']);

it('preserves a non-item credit and its signed amount without inventing a quantity', function () {
    Company::factory()->create(['netsuite_id' => 16]);
    $line = sourceCreditMemoLine(['item_id' => null, 'item_number' => null, 'quantity' => null, 'source_transaction_id' => null]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceCreditMemo()]))->push(sourcePage([$line]))
        ->push(sourcePage([sourceCreditMemo()]))->push(sourcePage([creditMemoHeaderTotals()]))
        ->push(sourcePage([creditMemoLineTotals(['quantity_count' => '0', 'quantity' => '0'])])))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertSuccessful();

    expect(Transaction::query()->sole()->foreign_total)->toBe('-25.12345678');
    expect(TransactionLine::query()->sole())->toMatchArray(['quantity' => null, 'item_id' => null, 'amount' => '25.12345678']);
});

it('refuses to overwrite another customer or transaction type', function (bool $otherCustomer) {
    $company = Company::factory()->create(['netsuite_id' => 16]);
    $existing = Transaction::factory()->create(['netsuite_id' => 1347,
        'company_id' => $otherCustomer ? Company::factory()->create()->id : $company->id,
        'type' => $otherCustomer ? 'CustCred' : 'CustInvc']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceCreditMemo()]))
        ->push(sourcePage([sourceCreditMemoLine()]))->push(sourcePage([sourceCreditMemo()])))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertFailed();

    expect($existing->refresh()->total)->toBe('100.00000000');
    expect($company->refresh()->credit_memos_synced_at)->toBeNull();
    $this->assertDatabaseCount('transaction_lines', 0);
})->with([true, false]);

it('rejects foreign source headers and lines before saving credit memos', function (array $header, array $line) {
    Company::factory()->create(['netsuite_id' => 16]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => fakeEmptyCreditApplications(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([sourceCreditMemo($header)]))
        ->push(sourcePage([sourceCreditMemoLine($line)])))]);

    $this->artisan('milkstool:sync-credit-memos', ['customer' => '16'])->assertFailed();

    $this->assertDatabaseCount('transactions', 0);
    $this->assertDatabaseCount('transaction_lines', 0);
})->with([
    'wrong customer' => [['customer_id' => '17'], []],
    'wrong type' => [['type' => 'CustInvc'], []],
    'foreign line' => [[], ['transaction_id' => '9999']],
]);
