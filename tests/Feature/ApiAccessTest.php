<?php

use App\Models\ApiClient;

it('returns 401 without a valid bearer token even without an accept header', function (?string $token) {
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    $this->get('/api/v1/status', $headers)->assertUnauthorized()
        ->assertHeader('content-type', 'application/json');
})->with([null, 'invalid-token']);

it('returns service status for a token with the required ability', function () {
    $client = ApiClient::factory()->create();
    $token = $client->createToken('test', ['status:read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/status')->assertOk()->assertExactJson([
        'data' => ['service' => 'Milkstool', 'api_version' => 'v1', 'status' => 'ok'],
    ]);
});

it('returns 403 when the token lacks the required ability', function () {
    $client = ApiClient::factory()->create();
    $token = $client->createToken('test', [])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/status')->assertForbidden();
});

it('returns 401 for expired tokens', function () {
    $this->freezeTime();
    $client = ApiClient::factory()->create();
    $token = $client->createToken('test', ['status:read'], now()->subMinute())->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/status')->assertUnauthorized();
});
