<?php

use App\Models\ApiClient;
use App\Models\Item;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeTime();
});

it('returns 401 without authentication and 403 without item read access', function () {
    $this->getJson('/api/v1/items?upc=001234567890')->assertUnauthorized();
    $this->getJson('/api/v1/items/1234')->assertUnauthorized();
    Sanctum::actingAs(ApiClient::factory()->create(), ['transactions:read', 'customers:all']);
    $this->getJson('/api/v1/items?upc=001234567890')->assertForbidden();
    $this->getJson('/api/v1/items/1234')->assertForbidden();
    Http::assertNothingSent();
});

it('finds exact UPC matches with leading zeros and stores integer NetSuite primary keys', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([sourceItem()]))]);

    $this->getJson('/api/v1/items?upc=%20001234567890%20')->assertOk()
        ->assertJsonPath('data.0', ['id' => 1234, 'item_number' => 'ABC-100', 'description' => 'Example product', 'upc' => '001234567890'])
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.match', 'exact')
        ->assertJsonPath('meta.checked_at', now()->utc()->toIso8601String())
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->assertDatabaseHas('items', ['id' => 1234, 'upc' => '001234567890']);
    expect(Item::query()->sole()->id)->toBe(1234);
    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], "item.upccode = '001234567890'")
        && $request->hasHeader('Prefer', 'transient'));
});

it('returns every item sharing a UPC instead of selecting the first', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        sourceItem(), sourceItem(['id' => '5678', 'item_number' => 'ABC-200']),
    ]))]);

    $this->getJson('/api/v1/items?upc=001234567890')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.id', 5678);

    $this->assertDatabaseCount('items', 2);
    Http::assertSentCount(1);
});

it('checks NetSuite again and updates an existing item without creating a new local ID', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);
    Item::factory()->create(['id' => 1234, 'description' => 'Old description']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([sourceItem()]))]);

    $this->getJson('/api/v1/items/1234')->assertOk()->assertJsonPath('data.id', 1234)
        ->assertJsonPath('data.description', 'Example product')->assertJsonMissingPath('data.inventory')
        ->assertJsonPath('meta.item.source_modified_at', '2026-11-18T12:00:00+00:00');

    $this->assertDatabaseCount('items', 1);
    $this->assertDatabaseHas('items', ['id' => 1234, 'description' => 'Example product']);
    Http::assertSentCount(1);
});

it('does not return an old cached UPC match when NetSuite reports no match', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);
    Item::factory()->create(['id' => 1234, 'upc' => '001234567890']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([]))]);

    $this->getJson('/api/v1/items?upc=001234567890')->assertOk()->assertJsonPath('data', []);

    Http::assertSentCount(1);
});

it('returns 404 only after NetSuite confirms an item is missing', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);
    Item::factory()->create(['id' => 1234]);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([]))]);

    $this->getJson('/api/v1/items/1234')->assertNotFound()->assertJsonPath('error.code', 'item_not_found');

    Http::assertSentCount(1);
});

it('returns 422 for invalid UPC input without sending a source query', function (string $query) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);

    $this->getJson('/api/v1/items'.$query)->assertUnprocessable()->assertJsonValidationErrors('upc');

    Http::assertNothingSent();
})->with(['missing' => '', 'empty' => '?upc=', 'array' => '?upc[]=1234', 'injection' => '?upc=123%27%20OR%201=1', 'too long' => '?upc='.str_repeat('1', 256)]);

it('returns 404 for invalid internal IDs without querying NetSuite', function (string $id) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);

    $this->getJson('/api/v1/items/'.$id)->assertNotFound();

    Http::assertNothingSent();
})->with(['0', '-1', 'abc', '9999999999999999999999999']);

it('returns 503 rather than not found or stale data when NetSuite fails', function (int $status) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);
    Item::factory()->create(['id' => 1234, 'description' => 'Keep this']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(['detail' => 'Private upstream information'], $status)]);

    $this->getJson('/api/v1/items/1234')->assertServiceUnavailable()->assertJsonPath('error.code', 'source_unavailable')
        ->assertJsonMissingPath('data')->assertDontSee('Private upstream information');

    $this->assertDatabaseHas('items', ['id' => 1234, 'description' => 'Keep this']);
    Http::assertSentCount(1);
})->with([403, 429, 500]);

it('returns 503 on a connection failure without serving a cached result', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::failedConnection()]);

    $this->getJson('/api/v1/items?upc=001234567890')->assertServiceUnavailable()->assertJsonPath('error.code', 'source_unavailable');

    $this->assertDatabaseCount('items', 0);
    Http::assertSentCount(1);
});

it('returns 503 and saves no partial matches for invalid upstream data', function (array $page) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response($page)]);

    $this->getJson('/api/v1/items?upc=001234567890')->assertServiceUnavailable()->assertJsonPath('error.code', 'source_unavailable');

    $this->assertDatabaseCount('items', 0);
    Http::assertSentCount(1);
})->with([
    'missing envelope' => [['items' => []]],
    'invalid item' => [sourcePage([sourceItem(), sourceItem(['id' => '5678', 'updated_at' => null])])],
    'wrong UPC' => [sourcePage([sourceItem(['upc' => '1234567890'])])],
    'duplicate ID' => [sourcePage([sourceItem(), sourceItem()])],
    'incomplete page' => [sourcePage([sourceItem()], true)],
]);

it('imports all pages of matching items', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);
    $items = array_map(fn (int $id): array => sourceItem(['id' => (string) $id]), range(1, 1000));
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage($items, true))->push(sourcePage([sourceItem()]))]);

    $this->getJson('/api/v1/items?upc=001234567890')->assertOk()->assertJsonCount(1001, 'data');

    $this->assertDatabaseCount('items', 1001);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'offset=1000'));
});

it('issues warehouse abilities without customer access and requires item access for inventory', function () {
    $this->artisan('milkstool:token:issue', ['client' => 'clipboard', '--inventory' => true])->assertFailed();
    $this->assertDatabaseCount('personal_access_tokens', 0);

    Artisan::call('milkstool:token:issue', ['client' => 'clipboard', '--items' => true, '--inventory' => true]);

    expect(ApiClient::query()->where('name', 'clipboard')->sole()->tokens()->sole()->abilities)
        ->toBe(['status:read', 'items:read', 'inventory:read']);
    Http::assertNothingSent();
});
