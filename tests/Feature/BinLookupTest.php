<?php

use App\Models\ApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->freezeTime();
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceBin(array $overrides = []): array
{
    return array_replace(['id' => '1', 'number' => 'A10A1', 'location_id' => '1', 'location_name' => 'Warehouse'], $overrides);
}

it('returns 401 without authentication and 403 without bin access', function () {
    $this->getJson('/api/v1/bins?number=A10A1')->assertUnauthorized();
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'inventory:read']);
    $this->getJson('/api/v1/bins?number=A10A1')->assertForbidden();

    Http::assertNothingSent();
});

it('requires item and inventory permissions to read bin contents', function (array $abilities) {
    Sanctum::actingAs(ApiClient::factory()->create(), $abilities);

    $this->getJson('/api/v1/bins/1')->assertForbidden();

    Http::assertNothingSent();
})->with([[['bins:read']], [['bins:read', 'items:read']], [['bins:read', 'inventory:read']]]);

it('returns exact case insensitive bin matches across locations using integer IDs', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['bins:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([
        sourceBin(), sourceBin(['id' => '2', 'location_id' => '2', 'location_name' => 'Second Warehouse']),
    ]))]);

    $this->getJson('/api/v1/bins?number=%20a10a1%20')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', ['id' => 1, 'number' => 'A10A1', 'location' => ['id' => 1, 'name' => 'Warehouse']])
        ->assertJsonPath('data.1.location.id', 2)->assertHeader('Cache-Control', 'no-store, private');

    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], "UPPER(bin.binnumber) = UPPER('a10a1')"));
});

it('escapes apostrophes and preserves leading zeros in bin numbers', function (string $number, string $literal) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['bins:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([]))]);

    $this->getJson('/api/v1/bins?'.http_build_query(['number' => $number]))->assertOk()->assertJsonPath('data', []);

    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], "UPPER('{$literal}')"));
})->with([['000123', '000123'], ["A'1", "A''1"]]);

it('returns 422 for invalid bin labels before contacting NetSuite', function (string $query) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['bins:read']);

    $this->getJson('/api/v1/bins'.$query)->assertUnprocessable()->assertJsonValidationErrors('number');

    Http::assertNothingSent();
})->with(['', '?number[]=', '?number=A%00B', '?number='.str_repeat('A', 256)]);

it('returns live bin contents with location scoped quantities and items without UPCs', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['bins:read', 'items:read', 'inventory:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceBin()]))
        ->push(sourcePage([['id' => '431', 'quantity_on_hand' => '173', 'quantity_available' => '172.125']]))
        ->push(sourcePage([['id' => '431', 'item_number' => 'VALVE-1', 'description' => 'Water Valve', 'upc' => null]]))]);

    $this->getJson('/api/v1/bins/1')->assertOk()->assertJsonPath('data.items.0', [
        'id' => 431, 'item_number' => 'VALVE-1', 'description' => 'Water Valve', 'upc' => null,
        'quantity_on_hand' => '173.00000000', 'quantity_available' => '172.12500000',
    ])->assertJsonPath('meta.inventory.complete', true)->assertJsonPath('meta.inventory.freshness', 'live');

    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], 'balance.binnumber = 1 AND balance.location = 1'));
    Http::assertSentCount(3);
});

it('distinguishes a known empty bin from a missing bin', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['bins:read', 'items:read', 'inventory:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceBin()]))->push(sourcePage([]))->push(sourcePage([]))]);

    $this->getJson('/api/v1/bins/1')->assertOk()->assertJsonPath('data.items', []);
    $this->getJson('/api/v1/bins/999')->assertNotFound()->assertJsonPath('error.code', 'bin_not_found');

    Http::assertSentCount(3);
});

it('does not return partial contents when item headers are missing', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['bins:read', 'items:read', 'inventory:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceBin()]))->push(sourcePage([['id' => '431', 'quantity_on_hand' => '1']]))->push(sourcePage([]))]);

    $this->getJson('/api/v1/bins/1')->assertServiceUnavailable()->assertJsonMissingPath('data');

    Http::assertSentCount(3);
});

it('returns 503 on a source failure instead of reporting an empty bin', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['bins:read', 'items:read', 'inventory:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage([sourceBin()]))->push([], 503)]);

    $this->getJson('/api/v1/bins/1')->assertServiceUnavailable()->assertJsonPath('error.code', 'source_unavailable');

    Http::assertSentCount(2);
});

it('rejects unexpected or duplicate source bins', function (array $rows) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['bins:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage($rows))]);

    $this->getJson('/api/v1/bins?number=A10A1')->assertServiceUnavailable();

    Http::assertSentCount(1);
})->with([[[sourceBin(), sourceBin()]], [[sourceBin(['number' => 'WRONG'])]]]);

it('issues bin permissions only with item and inventory access', function () {
    $this->artisan('milkstool:token:issue', ['client' => 'clipboard', '--bins' => true])->assertFailed();
    $this->assertDatabaseCount('personal_access_tokens', 0);

    Artisan::call('milkstool:token:issue', ['client' => 'clipboard', '--bins' => true, '--items' => true, '--inventory' => true]);

    expect(ApiClient::query()->where('name', 'clipboard')->sole()->tokens()->sole()->abilities)
        ->toContain('bins:read', 'items:read', 'inventory:read');
});
