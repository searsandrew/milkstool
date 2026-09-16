<?php

use App\Models\ApiClient;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

it('issues a usable expiring token and reuses the client during rotation', function () {
    $this->travelTo(now()->startOfSecond());
    $client = ApiClient::factory()->create(['name' => 'saturn-v']);
    $existingToken = $client->createToken('existing', ['status:read']);

    $exitCode = Artisan::call('milkstool:token:issue', ['client' => 'saturn-v', '--days' => '30']);
    $output = Artisan::output();
    preg_match('/[0-9]+\|[a-zA-Z0-9]+/', $output, $matches);

    expect($exitCode)->toBe(0);
    $this->assertDatabaseCount('api_clients', 1);
    $this->assertDatabaseCount('personal_access_tokens', 2);
    $this->assertModelExists($existingToken->accessToken);
    $token = PersonalAccessToken::findToken($matches[0]);
    expect($token->expires_at->equalTo(now()->addDays(30)))->toBeTrue();
    expect($token->abilities)->toBe(['status:read']);
    expect($token->token)->not->toBe($matches[0]);
    $this->withToken($matches[0])->getJson('/api/v1/status')->assertOk();
});

it('creates a new client when issuing its first token', function () {
    $this->artisan('milkstool:token:issue', ['client' => 'saturn-v'])->assertSuccessful();

    $this->assertDatabaseHas('api_clients', ['name' => 'saturn-v']);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('rejects invalid token requests without creating records', function (string $client, string $days) {
    $this->artisan('milkstool:token:issue', ['client' => $client, '--days' => $days])->assertFailed();

    $this->assertDatabaseCount('api_clients', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'empty client' => ['', '90'],
    'invalid slug' => ['Bad Client', '90'],
    'overlong name' => [str_repeat('a', 101), '90'],
    'zero lifetime' => ['saturn-v', '0'],
    'fractional lifetime' => ['saturn-v', '1.5'],
    'excessive lifetime' => ['saturn-v', '3651'],
]);

it('revokes a token and prevents subsequent API access', function () {
    $client = ApiClient::factory()->create();
    $token = $client->createToken('test', ['status:read']);

    $this->artisan('milkstool:token:revoke', ['client' => $client->name, 'token' => $token->accessToken->id])
        ->expectsOutput('Token revoked.')->assertSuccessful();

    $this->assertModelMissing($token->accessToken);
    $this->withToken($token->plainTextToken)->getJson('/api/v1/status')->assertUnauthorized();
});

it('does not revoke another client token', function () {
    $client = ApiClient::factory()->create();
    $other = ApiClient::factory()->create();
    $token = $other->createToken('test', ['status:read']);

    $this->artisan('milkstool:token:revoke', ['client' => $client->name, 'token' => $token->accessToken->id])
        ->expectsOutput('Token not found for this client.')->assertFailed();

    $this->assertModelExists($token->accessToken);
});

it('rejects invalid token identifiers', function () {
    $this->artisan('milkstool:token:revoke', ['client' => 'saturn-v', 'token' => '1oops'])
        ->expectsOutput('Token ID must be a positive integer.')->assertFailed();
});

it('reports missing clients for token listing', function () {
    $this->artisan('milkstool:token:list', ['client' => 'missing'])
        ->expectsOutput('Client not found.')->assertFailed();
});

it('lists only the requested client token metadata without exposing secrets', function () {
    $this->travelTo(now()->startOfSecond());
    $client = ApiClient::factory()->create();
    $token = $client->createToken('test', ['status:read'], now()->addDays(30));
    $other = ApiClient::factory()->create();
    $other->createToken('other', ['private:ability']);

    $exitCode = Artisan::call('milkstool:token:list', ['client' => $client->name]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('status:read', $token->accessToken->expires_at->toDateTimeString())
        ->not->toContain($token->plainTextToken, $token->accessToken->token, 'private:ability');
});

it('seeds known clients idempotently without issuing credentials', function () {
    $this->seed();
    $this->seed();

    $this->assertDatabaseCount('api_clients', 2);
    $this->assertDatabaseHas('api_clients', ['name' => 'saturn-v']);
    $this->assertDatabaseHas('api_clients', ['name' => 'admin']);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});
