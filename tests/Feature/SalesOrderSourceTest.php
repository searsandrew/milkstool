<?php

use App\Services\NetSuite\SalesOrderSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    fakeNetSuiteConfiguration();
});

it('paginates orders by increasing IDs and scopes every page to the customer and sales-order type', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceOrder()], true))
        ->push(sourcePage([sourceOrder(['id' => '205'])]))]);

    $orders = iterator_to_array(app(SalesOrderSource::class)->orders(16));

    expect(array_column($orders, 'id'))->toBe(['101', '205']);
    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], "entity = 16 AND type = 'SalesOrd' AND id > 101 ORDER BY id")
        && $request->hasHeader('Prefer', 'transient'));
});

it('paginates order lines including the mainline and retains source signs and nulls', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceLine(['line_id' => '0', 'mainline' => 'T', 'quantity' => null])], true))
        ->push(sourcePage([sourceLine()]))]);

    $lines = app(SalesOrderSource::class)->lines(16, 101);

    expect(array_column($lines, 'line_id'))->toBe(['0', '1']);
    expect($lines[0]['quantity'])->toBeNull();
    expect($lines[1]['quantity'])->toBe('-2.50000000');
    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], 'transactionline.id > 0')
        && str_contains($request['q'], 'transaction.entity = 16'));
});

it('rejects malformed pagination instead of marking an incomplete import complete', function (array $page) {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response($page)]);

    expect(fn () => iterator_to_array(app(SalesOrderSource::class)->orders(16)))->toThrow(Exception::class);
})->with([
    'missing items' => [['hasMore' => false]],
    'missing more flag' => [['items' => []]],
    'empty continuation' => [['items' => [], 'hasMore' => true]],
]);

it('rejects foreign-customer orders', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        sourceOrder(['customer_id' => '17']),
    ]))]);

    expect(fn () => iterator_to_array(app(SalesOrderSource::class)->orders(16)))->toThrow(ValidationException::class);
});

it('rejects duplicate or backward order IDs', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        sourceOrder(), sourceOrder(),
    ]))]);

    expect(fn () => iterator_to_array(app(SalesOrderSource::class)->orders(16)))->toThrow(RuntimeException::class);
});

it('rejects duplicate lines before an importer can overwrite existing data', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        sourceLine(), sourceLine(),
    ]))]);

    expect(fn () => app(SalesOrderSource::class)->lines(16, 101))->toThrow(ValidationException::class);
});

it('rejects missing customers', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([]))]);

    expect(fn () => app(SalesOrderSource::class)->customer(16))->toThrow(RuntimeException::class);
});

it('rejects an empty line response instead of clearing saved lines', function () {
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([]))]);

    expect(fn () => app(SalesOrderSource::class)->lines(16, 101))->toThrow(RuntimeException::class);
});
