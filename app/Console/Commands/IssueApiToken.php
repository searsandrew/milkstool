<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\NewAccessToken;

class IssueApiToken extends Command
{
    protected $signature = 'milkstool:token:issue {client : Lowercase client slug} {--days=90 : Token lifetime in days}';

    protected $description = 'Create a client if needed and issue a status:read API token';

    public function handle(): int
    {
        $validator = Validator::make([
            'client' => $this->argument('client'),
            'days' => $this->option('days'),
        ], [
            'client' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'days' => ['required', 'integer', 'between:1,3650'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $token = DB::transaction(function (): NewAccessToken {
            $client = ApiClient::query()->firstOrCreate(['name' => $this->argument('client')]);

            return $client->createToken('service', ['status:read'], now()->addDays((int) $this->option('days')));
        });

        $this->info('Token ID: '.$token->accessToken->getKey());
        $this->line('Store this token securely; it is only displayed once.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
