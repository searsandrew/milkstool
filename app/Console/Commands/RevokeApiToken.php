<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class RevokeApiToken extends Command
{
    protected $signature = 'milkstool:token:revoke {client : Client slug} {token : Token ID}';

    protected $description = 'Revoke one API token belonging to the specified client';

    public function handle(): int
    {
        $tokenId = (string) $this->argument('token');

        if (! ctype_digit($tokenId) || (int) $tokenId < 1) {
            $this->error('Token ID must be a positive integer.');

            return self::FAILURE;
        }

        $client = ApiClient::query()->where('name', $this->argument('client'))->first();
        $token = $client?->tokens()->whereKey($tokenId)->first();

        if ($token === null) {
            $this->error('Token not found for this client.');

            return self::FAILURE;
        }

        $token->delete();
        $this->info('Token revoked.');

        return self::SUCCESS;
    }
}
