<?php

use App\Models\ApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    Http::preventStrayRequests();
});

/** @return array<string, mixed> */
function itemHistoryRow(int $id = 900): array
{
    return ['id' => (string) $id, 'number' => 'SO-900', 'order_date' => '2026-11-19',
        'entity_id' => '45', 'entity_name' => 'Example Customer', 'customer_po_reference' => 'CUSTOMER-PO-1', 'status' => 'Pending Fulfillment'];
}

it('requires item and dedicated item order access without inheriting customer transaction access', function () {
    $this->getJson('/api/v1/items/431/orders?type=sales')->assertUnauthorized();
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'transactions:read', 'customers:all']);
    $this->getJson('/api/v1/items/431/orders?type=sales')->assertForbidden();
    Sanctum::actingAs(ApiClient::factory()->create(), ['item-orders:read']);
    $this->getJson('/api/v1/items/431/orders?type=purchase')->assertForbidden();

    Http::assertNothingSent();
});

it('reads live recent sales with customer PO references and integer record IDs', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([itemHistoryRow()]))]);

    $this->getJson('/api/v1/items/431/orders?type=sales')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 900)->assertJsonPath('data.0.entity.id', 45)
        ->assertJsonPath('data.0.customer_po_reference', 'CUSTOMER-PO-1')->assertJsonPath('meta.item_id', 431)
        ->assertJsonPath('meta.type', 'sales')->assertJsonPath('meta.freshness', 'live')
        ->assertHeader('Cache-Control', 'no-store, private');

    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], "transaction.type = 'SalesOrd'")
        && str_contains($request['q'], 'transactionline.item = 431')
        && str_contains($request['q'], "transactionline.mainline = 'F'")
        && str_contains($request['q'], 'EXISTS') && str_contains($request['q'], 'ORDER BY transaction.trandate DESC, transaction.id DESC'));
});

it('queries vendor purchase orders separately without customer PO references', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);
    $row = array_replace(itemHistoryRow(), ['number' => 'PO-55', 'entity_name' => 'Example Vendor']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([$row]))]);

    $this->getJson('/api/v1/items/431/orders?type=purchase')->assertOk()->assertJsonPath('data.0.number', 'PO-55')
        ->assertJsonPath('data.0.entity.name', 'Example Vendor')->assertJsonPath('data.0.customer_po_reference', null);

    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], "transaction.type = 'PurchOrd'"));
});

it('limits history to ten records and reports that older orders exist', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);
    $rows = array_map(fn (int $id): array => itemHistoryRow($id), range(911, 901));
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage($rows))]);

    $this->getJson('/api/v1/items/431/orders?type=sales')->assertOk()->assertJsonCount(10, 'data')
        ->assertJsonPath('data.0.id', 911)->assertJsonPath('data.9.id', 902)->assertJsonPath('meta.has_more', true);

    Http::assertSentCount(1);
});

it('returns an empty list when no orders contain the item', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage([]))]);

    $this->getJson('/api/v1/items/431/orders?type=purchase')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.has_more', false);

    Http::assertSentCount(1);
});

it('rejects unsupported order types before querying NetSuite', function (string $query) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);

    $this->getJson('/api/v1/items/431/orders'.$query)->assertUnprocessable()->assertJsonValidationErrors('type');

    Http::assertNothingSent();
})->with(['', '?type=invoice', '?type[]=sales', '?type=SalesOrd']);

it('rejects invalid item IDs before querying NetSuite', function (string $id) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);

    $this->getJson('/api/v1/items/'.$id.'/orders?type=sales')->assertNotFound();

    Http::assertNothingSent();
})->with(['0', '-1', '99999999999999999999']);

it('rejects malformed duplicate or unordered source history without exposing details', function (string $case) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);
    $rows = match ($case) {
        'duplicate' => [itemHistoryRow(), itemHistoryRow()],
        'unordered' => [itemHistoryRow(900), itemHistoryRow(901)],
        default => [array_replace(itemHistoryRow(), ['order_date' => 'bad-date'])],
    };
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(sourcePage($rows))]);

    $this->getJson('/api/v1/items/431/orders?type=sales')->assertServiceUnavailable()->assertJsonPath('error.code', 'source_unavailable');

    Http::assertSentCount(1);
})->with(['duplicate', 'unordered', 'malformed']);

it('does not turn a NetSuite permission failure into empty history', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::response(['message' => 'Private account detail'], 403)]);

    $this->getJson('/api/v1/items/431/orders?type=purchase')->assertServiceUnavailable()->assertDontSee('Private account detail');

    Http::assertSentCount(1);
});

it('issues item history access only with the item read flag', function () {
    $this->artisan('milkstool:token:issue', ['client' => 'clipboard', '--item-orders' => true])->assertFailed();
    $this->assertDatabaseCount('personal_access_tokens', 0);

    expect(Artisan::call('milkstool:token:issue', ['client' => 'clipboard', '--items' => true, '--item-orders' => true]))->toBe(0);
    preg_match('/[0-9]+\|[a-zA-Z0-9]+/', Artisan::output(), $matches);
    $token = PersonalAccessToken::findToken($matches[0]);
    expect($token->abilities)->toContain('items:read', 'item-orders:read')->not->toContain('customers:all');
});

it('continues after the last date and ID without duplicating same-day orders', function () {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);
    Http::fake(['https://netsuite.example/services/rest/query/v1/suiteql*' => Http::sequence()
        ->push(sourcePage(array_map(fn (int $id): array => itemHistoryRow($id), range(911, 901))))
        ->push(sourcePage([itemHistoryRow(901), array_replace(itemHistoryRow(950), ['order_date' => '2026-11-18'])]))]);
    $first = $this->getJson('/api/v1/items/431/orders?type=sales')->assertOk()->assertJsonCount(10, 'data');
    $cursor = $first->json('meta.next_cursor');

    $this->getJson('/api/v1/items/431/orders?type=sales&cursor='.urlencode($cursor))->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', 901)->assertJsonPath('meta.next_cursor', null);

    Http::assertSent(fn (Request $request): bool => str_contains($request['q'], 'transaction.id < 902')
        && str_contains($request['q'], "transaction.trandate < TO_DATE('2026-11-19', 'YYYY-MM-DD')"));
});

it('rejects tampered or mismatched pagination cursors without querying NetSuite', function (string $case) {
    Sanctum::actingAs(ApiClient::factory()->create(), ['items:read', 'item-orders:read']);
    $payload = ['item_id' => 431, 'type' => 'sales', 'date' => '2026-11-19', 'id' => 900];
    if ($case === 'wrong item') {
        $payload['item_id'] = 999;
    } elseif ($case === 'wrong type') {
        $payload['type'] = 'purchase';
    }
    $cursor = $case === 'tampered' ? 'invalid-cursor' : Crypt::encryptString(json_encode($payload));

    $this->getJson('/api/v1/items/431/orders?type=sales&cursor='.urlencode($cursor))
        ->assertUnprocessable()->assertJsonValidationErrors('cursor');

    Http::assertNothingSent();
})->with(['tampered', 'wrong item', 'wrong type']);
