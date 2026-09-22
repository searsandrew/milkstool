<?php

use App\Actions\SyncPayments;
use App\Jobs\RefreshPayments;
use App\Models\Company;
use App\Models\PaymentApplication;
use App\Models\Transaction;
use App\Services\NetSuite\PaymentApplicationSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->company = Company::factory()->create(['netsuite_id' => 16]);
});

/** @return array<string, mixed> */
function paymentHeader(): array
{
    return sourceInvoice(['id' => '1517', 'type' => 'CustPymt', 'number' => 'PYMT02', 'status' => 'C',
        'total' => '100', 'foreign_total' => '100', 'foreign_amount_paid' => null, 'foreign_amount_unpaid' => null, 'due_date' => null]);
}

/** @return array<string, mixed> */
function paymentLine(): array
{
    return sourceInvoiceLine(['transaction_id' => '1517', 'item_id' => null, 'item_number' => null,
        'quantity' => null, 'rate' => null, 'amount' => '-100', 'source_transaction_id' => '1347']);
}

/** @return list<array<string, mixed>> */
function paymentApplications(): array
{
    return [
        ['payment_id' => '1517', 'payment_line_id' => '1', 'target_netsuite_id' => '1347', 'target_line_id' => '0',
            'target_customer_id' => '16', 'target_currency_id' => '1', 'target_type' => 'CustInvc', 'foreign_amount' => '60'],
        ['payment_id' => '1517', 'payment_line_id' => '1', 'target_netsuite_id' => '1489', 'target_line_id' => '0',
            'target_customer_id' => '16', 'target_currency_id' => '1', 'target_type' => 'CustInvc', 'foreign_amount' => '40'],
    ];
}

/** @param list<array<string, mixed>> $applications
 * @param  list<array<string, mixed>>|null  $verification
 */
function fakePaymentSync(array $applications, ?array $verification = null, string $controlAmount = '100'): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $totals = $applications === [] ? [] : [['currency_id' => '1', 'application_count' => (string) count($applications),
        'application_amount_count' => (string) count(array_filter($applications, fn ($row) => isset($row['foreign_amount']))),
        'application_amount' => $controlAmount]];
    Http::fake(['https://netsuite.example/*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([paymentHeader()]))->push(sourcePage([paymentLine()]))
        ->push(sourcePage($applications))->push(sourcePage($verification ?? $applications))->push(sourcePage([paymentHeader()]))
        ->push(sourcePage([['currency_id' => '1', 'payment_count' => '1', 'paid_count' => '0', 'unpaid_count' => '0',
            'foreign_amount_paid' => '0', 'foreign_amount_unpaid' => '0', 'total' => '100', 'foreign_total' => '100']]))
        ->push(sourcePage([['currency_id' => '1', 'line_count' => '1', 'quantity_count' => '0', 'amount_count' => '1',
            'quantity' => '0', 'amount' => '-100', 'detail_amount' => '-100']]))->push(sourcePage($totals))]);
}

it('imports one payment applied to multiple invoices and reconciles every metric', function () {
    fakePaymentSync(paymentApplications());
    $result = app(SyncPayments::class)->handle(16);
    expect($result['payments'])->toBe(1);
    expect($result['reconciliation'])->toHaveCount(16);
    expect(collect($result['reconciliation'])->every('matches'))->toBeTrue();
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('payment_applications', 2);
    expect(Transaction::query()->sole()->foreign_total)->toBe('100.00000000');
    expect(PaymentApplication::query()->orderBy('target_netsuite_id')->first()->foreign_amount)->toBe('60.00000000');
    expect($this->company->refresh()->payments_backfilled_at)->not->toBeNull();
    expect($this->company->invoices_synced_at)->toBeNull();
    Http::assertSentCount(9);
});

it('replaces changed applications even when the payment modification timestamp does not change', function () {
    fakePaymentSync(paymentApplications());
    app(SyncPayments::class)->handle(16);
    $applications = [array_replace(paymentApplications()[0], ['foreign_amount' => '100'])];
    fakePaymentSync($applications);
    app(SyncPayments::class)->handle(16);
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('payment_applications', 1);
    expect(PaymentApplication::query()->sole()->foreign_amount)->toBe('100.00000000');
});

it('removes applications only after verifying an empty application snapshot', function () {
    fakePaymentSync(paymentApplications());
    app(SyncPayments::class)->handle(16);
    fakePaymentSync([]);
    app(SyncPayments::class)->handle(16);
    $this->assertDatabaseCount('payment_applications', 0);
    expect(Transaction::query()->sole()->foreign_total)->toBe('100.00000000');
});

it('retains previously stored applications if the source changes during collection', function () {
    $payment = Transaction::factory()->for($this->company)->create(['netsuite_id' => 1517, 'type' => 'CustPymt']);
    $existing = PaymentApplication::factory()->for($payment)->create();
    fakePaymentSync(paymentApplications(), []);
    $this->artisan('milkstool:sync-payments', ['customer' => 16])->assertFailed();
    $this->assertModelExists($existing);
    expect($this->company->refresh()->payments_synced_at)->toBeNull();
});

it('does not advance freshness when application reconciliation differs', function () {
    $this->company->forceFill(['payments_synced_at' => '2026-09-01 12:00:00'])->save();
    fakePaymentSync(paymentApplications(), controlAmount: '99');
    $this->artisan('milkstool:sync-payments', ['customer' => 16])->assertFailed();
    expect($this->company->refresh()->payments_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 12:00:00');
    expect($this->company->payments_backfilled_at)->toBeNull();
    expect($this->company->payments_sync_error)->not->toBeNull();
});

it('preserves null amounts and inaccessible target metadata', function () {
    $applications = [array_replace(paymentApplications()[0], ['foreign_amount' => null, 'target_customer_id' => null,
        'target_currency_id' => null, 'target_type' => null])];
    fakePaymentSync($applications, controlAmount: '0');
    $this->artisan('milkstool:sync-payments', ['customer' => 16])->assertSuccessful();
    expect(PaymentApplication::query()->sole()->foreign_amount)->toBeNull();
    expect(PaymentApplication::query()->sole()->target_customer_id)->toBeNull();
});

it('retains source relationships to other customers without moving transaction ownership', function () {
    fakePaymentSync([array_replace(paymentApplications()[0], ['target_customer_id' => '17', 'foreign_amount' => '100'])]);
    app(SyncPayments::class)->handle(16);
    expect(Transaction::query()->sole()->company_id)->toBe($this->company->id);
    expect(PaymentApplication::query()->sole()->target_customer_id)->toBe(17);
});

it('rejects application rows belonging to another payment', function () {
    Http::fake(['https://netsuite.example/*' => Http::response(sourcePage([
        array_replace(paymentApplications()[0], ['payment_id' => '999']),
    ]))]);
    expect(fn () => app(PaymentApplicationSource::class)->forPayments(16, [1517]))->toThrow(ValidationException::class);
});

it('paginates applications and rejects a repeated cursor', function (bool $duplicate) {
    $rows = paymentApplications();
    Http::fake(['https://netsuite.example/*' => Http::sequence()
        ->push(sourcePage([$rows[0]], true))->push(sourcePage([$duplicate ? $rows[0] : $rows[1]]))]);
    if ($duplicate) {
        expect(fn () => app(PaymentApplicationSource::class)->forPayments(16, [1517]))->toThrow(RuntimeException::class);
    } else {
        expect(app(PaymentApplicationSource::class)->forPayments(16, [1517])[1517])->toHaveCount(2);
    }
    Http::assertSent(fn ($request) => str_contains($request['q'], 'l.previousdoc > 1347'));
})->with([true, false]);

it('preserves stored data on a failed application page', function () {
    $payment = Transaction::factory()->for($this->company)->create(['netsuite_id' => 1517, 'type' => 'CustPymt']);
    $existing = PaymentApplication::factory()->for($payment)->create();
    Http::fake(['https://netsuite.example/*' => Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([paymentHeader()]))->push(sourcePage([paymentLine()]))
        ->push(sourcePage([paymentApplications()[0]], true))->push([], 503)]);
    $this->artisan('milkstool:sync-payments', ['customer' => 16])->assertFailed();
    $this->assertModelExists($existing);
});

it('refuses transaction collisions with another company or type', function (bool $otherCustomer) {
    Transaction::factory()->create(['netsuite_id' => 1517, 'type' => $otherCustomer ? 'CustPymt' : 'CustInvc',
        'company_id' => $otherCustomer ? Company::factory()->create()->id : $this->company->id]);
    fakePaymentSync(paymentApplications());
    $this->artisan('milkstool:sync-payments', ['customer' => 16])->assertFailed();
    $this->assertDatabaseCount('payment_applications', 0);
})->with([true, false]);

it('keeps missing source payments instead of silently deleting financial history', function () {
    $payment = Transaction::factory()->for($this->company)->create(['type' => 'CustPymt']);
    Http::fake(['https://netsuite.example/*' => Http::sequence()->push(sourcePage([sourceCustomer()]))->push(sourcePage([]))]);
    $this->artisan('milkstool:sync-payments', ['customer' => 16])->assertFailed();
    $this->assertModelExists($payment);
});

it('queues unique payment refreshes without making source requests', function () {
    $this->artisan('milkstool:sync-payments', ['customer' => 16, '--queue' => true])->assertSuccessful();
    $this->artisan('milkstool:sync-payments', ['customer' => 16, '--queue' => true])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();
    fakePaymentSync(paymentApplications());
    Queue::connection('netsuite')->pop('payments')->fire();
    expect($this->company->refresh()->payments_synced_at)->not->toBeNull();
});

it('backfills a customer once and skips it on subsequent runs', function () {
    fakePaymentSync(paymentApplications());
    $this->artisan('milkstool:backfill-payments', ['--limit' => 1])->assertSuccessful();
    $this->artisan('milkstool:backfill-payments')->expectsOutput('Backfill complete: 0 customers imported and reconciled.')->assertSuccessful();
    Http::assertSentCount(9);
});

it('does not change state when another payment sync holds the lock', function () {
    Cache::lock('netsuite-payments:16', 600)->get();
    $this->artisan('milkstool:sync-payments', ['customer' => 16])->assertFailed();
    expect($this->company->refresh()->payments_sync_started_at)->toBeNull();
    Http::assertNothingSent();
});

it('keeps an unapplied remainder without deriving it from application totals', function () {
    $applications = [array_replace(paymentApplications()[0], ['foreign_amount' => '0.00000001'])];
    fakePaymentSync($applications, controlAmount: '0.00000001');
    $this->artisan('milkstool:sync-payments', ['customer' => 16])->assertSuccessful();
    expect(PaymentApplication::query()->sole()->foreign_amount)->toBe('0.00000001');
    expect(Transaction::query()->sole()->foreign_total)->toBe('100.00000000');
    expect(Transaction::query()->sole()->foreign_amount_unpaid)->toBeNull();
});

it('lets transient payment errors retry while rejecting permission failures', function (int $status) {
    Http::fake(['https://netsuite.example/*' => Http::response([], $status)]);
    $job = (new RefreshPayments(16))->withFakeQueueInteractions();
    if ($status === 403) {
        $job->handle(app(SyncPayments::class));
        $job->assertFailedWith(RequestException::class);
    } else {
        expect(fn () => $job->handle(app(SyncPayments::class)))->toThrow(RequestException::class);
        $job->assertNotFailed();
    }
})->with([403, 429, 503]);

it('keeps a newer successful payment refresh when an older job fails', function () {
    $job = new RefreshPayments(16);
    $this->company->forceFill(['payments_synced_at' => now()->addMinute(), 'payments_next_sync_at' => now()->addHours(6)])->save();
    $job->failed(new RuntimeException('Old job'));
    expect($this->company->refresh()->payments_sync_error)->toBeNull();
});

it('stops a payment backfill at the first failed customer', function () {
    $next = Company::factory()->create(['netsuite_id' => 17]);
    Http::fake(['https://netsuite.example/*' => Http::response([], 403)]);
    $this->artisan('milkstool:backfill-payments')->assertFailed();
    expect($this->company->refresh()->payments_backfilled_at)->toBeNull();
    expect($next->refresh()->payments_sync_started_at)->toBeNull();
    Http::assertSentCount(1);
});

it('rejects invalid payment application requests before contacting NetSuite', function (int $customer, array $ids) {
    expect(fn () => app(PaymentApplicationSource::class)->forPayments($customer, $ids))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([[0, [1517]], [16, []], [16, [1517, 1517]], [16, ['1517']], [16, range(1, 51)]]);

it('refuses truncated application reconciliation data', function () {
    Http::fake(['https://netsuite.example/*' => Http::response(sourcePage([[
        'currency_id' => '1', 'application_count' => '1', 'application_amount_count' => '1', 'application_amount' => '100',
    ]], true))]);
    expect(fn () => app(PaymentApplicationSource::class)->controlTotals(16))->toThrow(RuntimeException::class);
});

it('keeps scheduled payments disabled until enabled and skips inactive queued customers', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'milkstool:dispatch-payment-refreshes'));
    config()->set('netsuite-sync.scheduled', false);
    expect($event->filtersPass(app()))->toBeFalse();
    config()->set('netsuite-sync.scheduled', true);
    expect($event->filtersPass(app()))->toBeTrue();
    $this->company->forceFill(['is_active' => false])->save();
    (new RefreshPayments(16))->handle(app(SyncPayments::class));
    Http::assertNothingSent();
});
