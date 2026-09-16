<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class ListApiTokens extends Command
{
    protected $signature = 'milkstool:token:list {client : Client slug}';

    protected $description = 'List a client token IDs, abilities, expiration, and last use without revealing secrets';

    public function handle(): int
    {
        $client = ApiClient::query()->where('name', $this->argument('client'))->first();

        if ($client === null) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $this->table(['ID', 'Abilities', 'Expires at (UTC)', 'Last used (UTC)'],
            $client->tokens()->orderBy('id')->get()->map(fn (PersonalAccessToken $token): array => [
                $token->getKey(),
                implode(', ', $token->abilities),
                $token->expires_at?->toDateTimeString() ?? 'Never',
                $token->last_used_at?->toDateTimeString() ?? 'Never',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
