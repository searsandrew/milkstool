<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\NewAccessToken;

class IssueApiToken extends Command
{
    protected $signature = 'milkstool:token:issue {client : Lowercase client slug} {--bins : Allow bin lookup and contents, requires --items and --inventory} {--items : Allow item and UPC reads} {--inventory : Allow live inventory reads, requires --items} {--credit-memos : Allow targeted credit memo refreshes for granted customers} {--invoices : Allow targeted invoice refreshes for granted customers} {--orders : Allow requesting imports of submitted sales orders for granted customers} {--activity : Allow recording activity for granted customers} {--days=90 : Token lifetime in days} {--customer=* : Grant transaction reads for these NetSuite IDs, or all for a trusted service}';

    protected $description = 'Create a client if needed and issue an API token with optional item, inventory, and customer transaction access';

    public function handle(): int
    {
        $validator = Validator::make([
            'bins' => $this->option('bins'),
            'inventory' => $this->option('inventory'),
            'items' => $this->option('items'),
            'client' => $this->argument('client'),
            'days' => $this->option('days'),
            'customers' => $this->option('customer'),
        ], [
            'items' => ['accepted_if:inventory,true', 'accepted_if:bins,true'],
            'inventory' => ['accepted_if:bins,true'],
            'client' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'days' => ['required', 'integer', 'between:1,3650'],
            'customers' => ['array', ...(($this->option('activity') || $this->option('orders') || $this->option('invoices') || $this->option('credit-memos')) ? ['min:1'] : [])],
            'customers.*' => ['required', 'string', 'regex:/\A(?:all|[1-9][0-9]*)\z/'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $abilities = ['status:read'];
        if ($this->option('bins')) {
            $abilities[] = 'bins:read';
        }
        if ($this->option('items')) {
            $abilities[] = 'items:read';
        }
        if ($this->option('inventory')) {
            $abilities[] = 'inventory:read';
        }
        if ($this->option('customer') !== []) {
            $abilities[] = 'transactions:read';
            foreach (array_unique($this->option('customer')) as $customer) {
                $abilities[] = $customer === 'all' ? 'customers:all' : 'customer:'.$customer;
            }
        }

        if ($this->option('activity')) {
            $abilities[] = 'activity:write';
        }

        if ($this->option('orders')) {
            $abilities[] = 'orders:refresh';
        }

        if ($this->option('invoices')) {
            $abilities[] = 'invoices:refresh';
        }

        if ($this->option('credit-memos')) {
            $abilities[] = 'credit-memos:refresh';
        }

        $token = DB::transaction(function () use ($abilities): NewAccessToken {
            $client = ApiClient::query()->firstOrCreate(['name' => $this->argument('client')]);

            return $client->createToken('service', $abilities, now()->addDays((int) $this->option('days')));
        });

        $this->info('Token ID: '.$token->accessToken->getKey());
        $this->line('Store this token securely; it is only displayed once.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
