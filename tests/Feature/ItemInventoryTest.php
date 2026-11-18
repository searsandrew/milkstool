<?php

use App\Models\ApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeTime();
});

it('returns 403 without inventory access before retrieving any item data', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read']);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertForbidden();

    Http::assertNothingSent();
});

it('returns 403 with inventory access but no item access', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['inventory:read']);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertForbidden();

    Http::assertNothingSent();
});

it('returns fresh inventory with integer IDs independent location totals and base units', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceItem()]))
        ->push(sourcePage([['unit_type_id' => '5']]))
        ->push(sourcePage([sourceItemLocation(), sourceItemLocation(['id' => '20', 'name' => 'Overflow', 'quantity_on_hand' => '3.125', 'quantity_available' => null])]))
        ->push(sourcePage([sourceItemBin()])),
        'https://netsuite.example/services/rest/record/v1/unitsType/5*' => Http::response(['id' => '5', 'uom' => ['items' => [
            ['internalId' => '1', 'unitName' => 'Each', 'baseUnit' => true, 'conversionRate' => 1.0],
            ['internalId' => '2', 'unitName' => 'Case', 'baseUnit' => false, 'conversionRate' => 12.0],
        ]]]),
    ]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertOk()
        ->assertJsonPath('data.inventory', [
            'unit' => ['id' => 1, 'name' => 'Each'],
            'locations' => [
                ['id' => 10, 'name' => 'Main Warehouse', 'quantity_on_hand' => '24.00000000', 'quantity_available' => '20.00000000',
                    'bins' => [['id' => 101, 'number' => 'A-01-02', 'quantity_on_hand' => '12.00000000', 'quantity_available' => '10.00000000']]],
                ['id' => 20, 'name' => 'Overflow', 'quantity_on_hand' => '3.12500000', 'quantity_available' => null, 'bins' => []],
            ],
        ])->assertJsonPath('meta.inventory', [
            'started_at' => now()->utc()->toIso8601String(), 'checked_at' => now()->utc()->toIso8601String(),
            'freshness' => 'live', 'complete' => true, 'quantity_basis' => 'base_unit',
        ])->assertHeader('Cache-Control', 'no-store, private');

    Http::assertSent(fn (Request $request): bool => str_contains($request['q'] ?? '', 'GROUP BY balance.location, balance.binnumber'));
    $this->assertDatabaseHas('items', ['id' => 1234]);
});

it('returns empty locations and an unknown unit without inventing stock', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceItem()]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([]))]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertOk()
        ->assertJsonPath('data.inventory', ['unit' => null, 'locations' => []]);

    Http::assertSentCount(4);
});

it('checks inventory on every request rather than reusing a previous quantity', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceItem()]))->push(sourcePage([]))->push(sourcePage([sourceItemLocation()]))->push(sourcePage([]))
        ->push(sourcePage([sourceItem()]))->push(sourcePage([]))->push(sourcePage([sourceItemLocation(['quantity_available' => '-0.125'])]))->push(sourcePage([]))]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertOk()->assertJsonPath('data.inventory.locations.0.quantity_available', '20.00000000');
    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertOk()->assertJsonPath('data.inventory.locations.0.quantity_available', '-0.12500000');

    Http::assertSentCount(8);
});

it('returns 422 for unsupported inventory options before querying NetSuite', function (string $query) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);

    $this->getJson('/api/v1/items/1234?'.$query)->assertUnprocessable();

    Http::assertNothingSent();
})->with(['include=prices', 'include=inventory&inventory_freshness=cached', 'include=inventory&inventory_freshness=', 'include[]=inventory']);

it('returns 503 rather than partial inventory when a balance source fails', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceItem()]))->push(sourcePage([]))->push(sourcePage([sourceItemLocation()]))->push([], 503)]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'source_unavailable')->assertJsonMissingPath('data');

    Http::assertSentCount(4);
});

it('returns 503 for inconsistent inventory instead of claiming completeness', function (array $locations, array $bins) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceItem()]))->push(sourcePage([]))->push(sourcePage($locations))->push(sourcePage($bins))]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'source_unavailable');

    Http::assertSent(fn (Request $request): bool => str_contains($request['q'] ?? '', 'AggregateItemLocation'));
})->with([
    'duplicate location' => [[sourceItemLocation(), sourceItemLocation()], []],
    'missing location' => [[], [sourceItemBin()]],
    'duplicate bin' => [[sourceItemLocation()], [sourceItemBin(), sourceItemBin()]],
    'invalid quantity' => [[sourceItemLocation(['quantity_available' => 'unknown'])], []],
]);

it('retrieves every page of bin balances', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    $bins = array_map(fn (int $id): array => sourceItemBin(['id' => (string) $id, 'number' => 'BIN-'.$id]), range(1, 1000));
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceItem()]))->push(sourcePage([]))->push(sourcePage([sourceItemLocation()]))
        ->push(sourcePage($bins, true))->push(sourcePage([sourceItemBin(['id' => '1001'])]))]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertOk()->assertJsonCount(1001, 'data.inventory.locations.0.bins');

    Http::assertSentCount(5);
});

it('keeps live quantities available with an unknown unit when the role cannot read units', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    Http::fake([
        'https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
            ->push(sourcePage([sourceItem()]))->push(sourcePage([['unit_type_id' => '5']]))
            ->push(sourcePage([sourceItemLocation()]))->push(sourcePage([])),
        'https://netsuite.example/services/rest/record/v1/unitsType/5*' => Http::response([], 403),
    ]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertOk()
        ->assertJsonPath('data.inventory.unit', null)
        ->assertJsonPath('data.inventory.locations.0.quantity_on_hand', '24.00000000');

    Http::assertSentCount(5);
});

it('returns 503 when the units response does not identify a reliable base unit', function (array $units) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    Http::fake([
        'https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
            ->push(sourcePage([sourceItem()]))->push(sourcePage([['unit_type_id' => '5']])),
        'https://netsuite.example/services/rest/record/v1/unitsType/5*' => Http::response($units),
    ]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertServiceUnavailable();

    Http::assertSentCount(3);
})->with([
    'missing base unit' => [['id' => '5', 'uom' => ['items' => []]]],
    'wrong type' => [['id' => '6', 'uom' => ['items' => []]]],
    'truncated units' => [['id' => '5', 'uom' => ['items' => [], 'hasMore' => true]]],
    'wrong conversion' => [['id' => '5', 'uom' => ['items' => [['internalId' => '1', 'unitName' => 'Case', 'baseUnit' => true, 'conversionRate' => 12]]]]],
]);

it('reads the base unit integer ID from the NetSuite subresource self link', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    Http::fake([
        'https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
            ->push(sourcePage([sourceItem()]))->push(sourcePage([['unit_type_id' => '5']]))
            ->push(sourcePage([sourceItemLocation()]))->push(sourcePage([])),
        'https://netsuite.example/services/rest/record/v1/unitsType/5*' => Http::response([
            'id' => '5', 'uom' => ['items' => [[
                'links' => [['rel' => 'self', 'href' => 'https://netsuite.example/services/rest/record/v1/unitstype/5/uom/1']],
                'abbreviation' => 'Ea', 'baseUnit' => true, 'conversionRate' => 1.0, 'unitName' => 'Each',
            ]], 'totalResults' => 1],
        ]),
    ]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertOk()
        ->assertJsonPath('data.inventory.unit', ['id' => 1, 'name' => 'Each']);

    Http::assertSentCount(5);
});

it('returns 503 when a base unit link does not identify a unit in the requested type', function (array $links) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    Http::fake([
        'https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
            ->push(sourcePage([sourceItem()]))->push(sourcePage([['unit_type_id' => '5']])),
        'https://netsuite.example/services/rest/record/v1/unitsType/5*' => Http::response([
            'id' => '5', 'uom' => ['items' => [[
                'links' => $links, 'baseUnit' => true, 'conversionRate' => 1.0, 'unitName' => 'Each',
            ]]],
        ]),
    ]);

    $this->getJson('/api/v1/items/1234?include=inventory&inventory_freshness=live')->assertServiceUnavailable();

    Http::assertSentCount(3);
})->with([
    'missing link' => [[]],
    'wrong units type' => [[['rel' => 'self', 'href' => 'https://netsuite.example/services/rest/record/v1/unitstype/6/uom/1']]],
    'invalid unit ID' => [[['rel' => 'self', 'href' => 'https://netsuite.example/services/rest/record/v1/unitstype/5/uom/0']]],
]);
