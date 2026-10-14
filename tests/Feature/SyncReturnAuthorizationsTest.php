<?php

use App\Actions\SyncReturnAuthorizations;
use App\Jobs\RefreshReturnAuthorizations;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\NetSuite\ReturnAuthorizationSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceRma(array $overrides = []): array
{
    return sourceOrder(array_replace(['id' => '9001', 'type' => 'RtnAuth', 'number' => 'RMA-9001',
        'status' => 'A', 'status_name' => 'Return Authorization : Pending Approval', 'external_id' => 'saturn-v-return-example',
        'total' => '-25.12345678', 'foreign_total' => '-25.12345678'], $overrides));
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceRmaRecord(array $overrides = []): array
{
    $line = ['line' => 1, 'item' => ['id' => '55', 'refName' => 'ITEM-55'], 'orderDoc' => ['id' => '1347'], 'orderLine' => 1,
        'quantity' => '1.25000000', 'rate' => '10.04938271', 'amount' => '12.56172839', 'quantityReceived' => '0', 'quantityBilled' => '0', 'isClosed' => false,
        'description' => 'INV-1347: stopped working'];

    return array_replace_recursive(['id' => '9001', 'entity' => ['id' => '16'], 'currency' => ['id' => '1'],
        'lastModifiedDate' => '2026-09-01T12:00:00Z', 'total' => '25.12345678',
        'item' => ['items' => [$line, [...$line, 'line' => 2, 'orderDoc' => ['id' => '1450'], 'orderLine' => 3]]]], $overrides);
}

/** @param list<array<string, mixed>> $headers */
function fakeRmaRead(array $headers, array $record): void
{
    Http::fake([
        'https://netsuite.example/services/rest/query/v1/suiteql*' => function (Request $request) use ($headers) {
            return Http::response(sourcePage(str_contains($request['q'], 'FROM customer') ? [sourceCustomer()] : $headers));
        },
        'https://netsuite.example/services/rest/record/v1/returnAuthorization/9001*' => Http::response($record),
    ]);
}

it('stores one RMA and source references for lines from multiple invoices without NetSuite writes', function () {
    $company = Company::factory()->create(['id' => 16]);
    fakeRmaRead([sourceRma()], sourceRmaRecord());

    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16])->assertSuccessful();

    $this->assertDatabaseHas('transactions', ['id' => 9001, 'company_id' => 16, 'type' => 'RtnAuth',
        'status' => 'A', 'external_id' => 'saturn-v-return-example']);
    $this->assertDatabaseHas('transaction_lines', ['transaction_id' => 9001, 'netsuite_line_id' => 1, 'source_transaction_id' => 1347, 'source_transaction_line_id' => 1]);
    $this->assertDatabaseHas('transaction_lines', ['transaction_id' => 9001, 'netsuite_line_id' => 2, 'source_transaction_id' => 1450, 'source_transaction_line_id' => 3]);
    expect($company->refresh()->return_authorizations_synced_at)->not->toBeNull();
    expect($company->return_authorizations_backfilled_at)->not->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET' && parse_url($request->url(), PHP_URL_PATH) !== '/services/rest/query/v1/suiteql');
});

it('updates status and replaces removed lines without duplicating RMAs', function () {
    $company = Company::factory()->create(['id' => 16]);
    $existing = Transaction::factory()->for($company)->create(['id' => 9001, 'type' => 'RtnAuth', 'status' => 'A']);
    TransactionLine::factory()->for($existing)->create(['netsuite_line_id' => 99]);
    fakeRmaRead([sourceRma(['status' => 'B'])], sourceRmaRecord());

    app(SyncReturnAuthorizations::class)->handle(16);

    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('transaction_lines', 2);
    $this->assertDatabaseHas('transactions', ['id' => 9001, 'status' => 'B']);
    $this->assertDatabaseMissing('transaction_lines', ['transaction_id' => 9001, 'netsuite_line_id' => 99]);
});

it('retains existing lines and last success when the REST record is incomplete or belongs to another customer', function (array $record) {
    $company = Company::factory()->create(['id' => 16, 'return_authorizations_synced_at' => '2026-09-01 10:00:00']);
    $existing = Transaction::factory()->for($company)->create(['id' => 9001, 'type' => 'RtnAuth']);
    TransactionLine::factory()->for($existing)->create(['netsuite_line_id' => 99]);
    fakeRmaRead([sourceRma()], sourceRmaRecord($record));

    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16])->assertFailed();

    $this->assertDatabaseHas('transaction_lines', ['transaction_id' => 9001, 'netsuite_line_id' => 99]);
    $this->assertDatabaseHas('companies', ['id' => 16, 'return_authorizations_synced_at' => '2026-09-01 10:00:00']);
    expect($company->refresh()->return_authorizations_sync_error)->not->toBeNull();
    expect(Cache::lock('netsuite-return-authorizations:16', 600)->get())->toBeTrue();
})->with([
    'truncated items' => [['item' => ['hasMore' => true]]],
    'count mismatch' => [['item' => ['totalResults' => 3]]],
    'wrong customer' => [['entity' => ['id' => '17']]],
    'inconsistent total' => [['total' => '100']],
    'changed modification time' => [['lastModifiedDate' => '2026-09-01T12:01:00Z']],
    'half a source reference' => [['item' => ['items' => [['orderLine' => null]]]]],
]);

it('retains missing RMAs rather than treating an empty source scan as a successful deletion', function () {
    $company = Company::factory()->create(['id' => 16]);
    Transaction::factory()->for($company)->create(['id' => 9001, 'type' => 'RtnAuth']);
    fakeRmaRead([], sourceRmaRecord());

    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16])->assertFailed();

    $this->assertDatabaseHas('transactions', ['id' => 9001, 'company_id' => 16]);
    expect($company->refresh()->return_authorizations_synced_at)->toBeNull();
});

it('does not overwrite a transaction belonging to another customer or type', function (array $attributes) {
    Company::factory()->create(['id' => 16]);
    $existing = Transaction::factory()->create(['id' => 9001, ...$attributes]);
    fakeRmaRead([sourceRma()], sourceRmaRecord());

    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16])->assertFailed();

    $this->assertDatabaseHas('transactions', ['id' => 9001, 'company_id' => $existing->company_id, 'type' => $existing->type]);
})->with(['different customer' => [['type' => 'RtnAuth']], 'different type' => [['company_id' => 16, 'type' => 'CustInvc']]]);

it('refuses overlapping customer imports', function () {
    Company::factory()->create(['id' => 16]);
    $lock = Cache::lock('netsuite-return-authorizations:16', 600);
    $lock->get();

    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16])->assertFailed();

    Http::assertNothingSent();
    $lock->release();
});

it('rejects nonadvancing header pages', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([sourceRma()], true))]);

    expect(fn () => iterator_to_array(app(ReturnAuthorizationSource::class)->headers(16)))
        ->toThrow(ValidationException::class);
});

it('queues due active customers and reports RMA sync state', function () {
    Queue::fake();
    Company::factory()->create(['id' => 16]);
    Company::factory()->create(['id' => 17, 'is_active' => false]);
    Company::factory()->create(['id' => 18, 'return_authorizations_next_sync_at' => now()->addHour()]);

    $this->artisan('milkstool:dispatch-return-authorization-refreshes')->assertSuccessful();

    Queue::assertPushed(RefreshReturnAuthorizations::class, fn (RefreshReturnAuthorizations $job): bool => $job->customerId === 16 && $job->queue === 'return-authorizations');
    Queue::assertPushed(RefreshReturnAuthorizations::class, 1);
    $this->artisan('milkstool:sync-status', ['--type' => 'return-authorizations', '--customer' => 16, '--json' => true])->assertSuccessful();
    Http::assertNothingSent();
});

it('queues a customer once and supports dispatcher dry runs without contacting NetSuite', function () {
    Company::factory()->create(['id' => 16]);

    $this->artisan('milkstool:dispatch-return-authorization-refreshes', ['--dry-run' => true])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 0);
    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16, '--queue' => true])->assertSuccessful();
    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16, '--queue' => true])->assertSuccessful();

    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseHas('jobs', ['queue' => 'return-authorizations']);
    Http::assertNothingSent();
});

it('gates scheduled RMA refreshes on the existing scheduling switch', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'milkstool:dispatch-return-authorization-refreshes'));
    config()->set('netsuite-sync.scheduled', false);
    expect($event->filtersPass(app()))->toBeFalse();

    config()->set('netsuite-sync.scheduled', true);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('rejects invalid customer IDs without requesting NetSuite data', function (string $id) {
    $this->artisan('milkstool:sync-return-authorizations', ['customer' => $id])->assertFailed();

    Http::assertNothingSent();
})->with(['zero' => '0', 'malformed' => '16abc']);

it('retains the previous success when a listing changes during the import', function () {
    $company = Company::factory()->create(['id' => 16, 'return_authorizations_synced_at' => '2026-09-01 10:00:00']);
    $scans = 0;
    Http::fake([
        'https://netsuite.example/services/rest/query/v1/suiteql*' => function (Request $request) use (&$scans) {
            if (str_contains($request['q'], 'FROM customer')) {
                return Http::response(sourcePage([sourceCustomer()]));
            }
            $scans++;

            return Http::response(sourcePage([sourceRma(['status' => $scans === 1 ? 'A' : 'B'])]));
        },
        'https://netsuite.example/services/rest/record/v1/returnAuthorization/9001*' => Http::response(sourceRmaRecord()),
    ]);

    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16])->assertFailed();

    $this->assertDatabaseHas('companies', ['id' => 16, 'return_authorizations_synced_at' => '2026-09-01 10:00:00']);
    expect($company->refresh()->return_authorizations_sync_error)->not->toBeNull();
});

it('does not replace local lines when the record changes between reads', function () {
    $company = Company::factory()->create(['id' => 16]);
    $existing = Transaction::factory()->for($company)->create(['id' => 9001, 'type' => 'RtnAuth']);
    TransactionLine::factory()->for($existing)->create(['netsuite_line_id' => 99]);
    Http::fake([
        'https://netsuite.example/services/rest/query/v1/suiteql*' => function (Request $request) {
            return Http::response(sourcePage(str_contains($request['q'], 'FROM customer') ? [sourceCustomer()] : [sourceRma()]));
        },
        'https://netsuite.example/services/rest/record/v1/returnAuthorization/9001*' => Http::sequence()
            ->push(sourceRmaRecord())->push(sourceRmaRecord(['item' => ['items' => [['quantityReceived' => '1']]]])),
    ]);

    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16])->assertFailed();

    $this->assertDatabaseHas('transaction_lines', ['transaction_id' => 9001, 'netsuite_line_id' => 99]);
    $this->assertDatabaseCount('transaction_lines', 1);
});

it('reconciles negative SuiteQL return totals with positive REST amounts while preserving signed stored totals', function () {
    Company::factory()->create(['id' => 16]);
    fakeRmaRead([sourceRma(['total' => '-51.98', 'foreign_total' => '-51.98'])], sourceRmaRecord(['total' => '51.98']));

    $this->artisan('milkstool:sync-return-authorizations', ['customer' => 16])->assertSuccessful();

    $this->assertDatabaseHas('transactions', ['id' => 9001, 'total' => '-51.98', 'foreign_total' => '-51.98']);
    $this->assertDatabaseHas('companies', ['id' => 16, 'return_authorizations_sync_error' => null]);
});
