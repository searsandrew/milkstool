<?php

use App\Actions\SyncSalesOrders;
use App\Exceptions\SalesOrderSyncInterrupted;
use App\Jobs\RefreshCustomerBalance;
use App\Jobs\RefreshInvoices;
use App\Jobs\RefreshSalesOrders;
use App\Models\Company;
use App\Services\NetSuite\NetSuiteRestClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeSecond();
});

it('retries raw Guzzle connection failures with fresh signatures', function () {
    $authorizations = [];
    Http::fake(['https://netsuite.example/*' => function ($request) use (&$authorizations) {
        $authorizations[] = $request->header('Authorization')[0];
        if (count($authorizations) < 3) {
            throw new ConnectException('DNS unavailable', new Request('GET', $request->url()));
        }

        return Http::response(['id' => '16']);
    }]);

    expect(app(NetSuiteRestClient::class)->record('customer')->get(16)->json('id'))->toBe('16');
    expect(array_unique($authorizations))->toHaveCount(3);
});

it('normalizes an exhausted Guzzle connection failure for the queue', function () {
    $attempts = 0;
    Http::fake(['https://netsuite.example/*' => function ($request) use (&$attempts) {
        $attempts++;
        throw new ConnectException('DNS unavailable', new Request('POST', $request->url()));
    }]);

    expect(fn () => app(NetSuiteRestClient::class)->suiteql()->query('SELECT 1'))->toThrow(ConnectionException::class);
    expect($attempts)->toBe(3);
});

it('does not retry authentication errors or unsafe writes', function (string $method, int $status) {
    Http::fake(['https://netsuite.example/*' => Http::response([], $status)]);

    try {
        app(NetSuiteRestClient::class)->request($method, '/services/rest/record/v1/customer/16');
    } catch (RequestException) {
    }

    Http::assertSentCount(1);
})->with([['GET', 401], ['GET', 403], ['PATCH', 503]]);

it('defers different customer queues during one shared outage then resumes successfully', function () {
    Company::factory()->create(['id' => 16, 'is_active' => true]);
    $outage = true;
    $sequence = Http::sequence()->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([]));
    Http::fake(['https://netsuite.example/*' => function ($request) use (&$outage, $sequence) {
        if ($outage) {
            throw new ConnectException('DNS unavailable', new Request('POST', $request->url()));
        }

        return $sequence($request);
    }]);
    RefreshInvoices::dispatch(16);
    RefreshCustomerBalance::dispatch(16);

    $this->artisan('queue:work', ['connection' => 'netsuite', '--queue' => 'invoices', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->artisan('queue:work', ['connection' => 'netsuite', '--queue' => 'balances', '--once' => true, '--sleep' => 0])->assertSuccessful();

    expect(DB::table('jobs')->where('available_at', '>', now()->timestamp)->count())->toBe(2);
    expect(Company::findOrFail(16)->invoices_sync_error)->not->toContain('Background refresh failed');
    $this->assertDatabaseCount('failed_jobs', 0);
    expect(Company::findOrFail(16)->balance_sync_started_at)->toBeNull();

    $this->travel(304)->seconds();
    $outage = false;
    $this->artisan('queue:work', ['connection' => 'netsuite', '--queue' => 'invoices', '--once' => true, '--sleep' => 0])->assertSuccessful();

    expect(Company::findOrFail(16)->invoices_sync_error)->toBeNull();
    expect(Company::findOrFail(16)->invoices_synced_at)->not->toBeNull();
    $this->assertDatabaseCount('jobs', 1);
});

it('bounds real execution failures while preserving the source error', function () {
    Company::factory()->create(['id' => 16, 'is_active' => true]);
    Http::fake(['https://netsuite.example/*' => Http::response([], 503)]);
    RefreshInvoices::dispatch(16);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->artisan('queue:work', ['connection' => 'netsuite', '--queue' => 'invoices', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->travel(304)->seconds();
    }

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
    expect(DB::table('failed_jobs')->sole()->exception)->toContain('RequestException');
    Http::assertSentCount(3);
});

it('treats malformed source clocks as retryable without advancing a checkpoint', function (array $page) {
    Company::factory()->create(['id' => 16, 'is_active' => true, 'sales_orders_checkpoint_at' => now()->subDay()]);
    $checkpoint = Company::findOrFail(16)->sales_orders_checkpoint_at;
    Http::fake(['https://netsuite.example/*' => Http::sequence()->push(sourcePage([sourceCustomer()]))->push($page)]);
    $job = (new RefreshSalesOrders(16))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(SyncSalesOrders::class)))->toThrow(SalesOrderSyncInterrupted::class);
    $job->assertNotFailed();
    expect(Company::findOrFail(16)->sales_orders_checkpoint_at->equalTo($checkpoint))->toBeTrue();
})->with([
    [sourcePage([])],
    [['items' => [], 'hasMore' => true]],
    [sourcePage([['current_time' => 'invalid']])],
]);

it('honors a rate limit cooldown across queues without retrying HTTP immediately', function () {
    Company::factory()->create(['id' => 16, 'is_active' => true]);
    Http::fake(['https://netsuite.example/*' => Http::response([], 429, ['Retry-After' => '900'])]);
    RefreshInvoices::dispatch(16);
    RefreshCustomerBalance::dispatch(16);

    $this->artisan('queue:work', ['connection' => 'netsuite', '--queue' => 'invoices', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->travel(400)->seconds();
    $this->artisan('queue:work', ['connection' => 'netsuite', '--queue' => 'balances', '--once' => true, '--sleep' => 0])->assertSuccessful();

    Http::assertSentCount(1);
    $this->assertDatabaseCount('jobs', 2);
    expect(DB::table('jobs')->where('queue', 'balances')->sole()->available_at)->toBeGreaterThan(now()->timestamp);
    $this->assertDatabaseCount('failed_jobs', 0);
});
