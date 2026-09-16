<?php

namespace Database\Seeders;

use App\Models\ApiClient;
use Illuminate\Database\Seeder;

class ApiClientSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['saturn-v', 'admin'] as $name) {
            ApiClient::query()->firstOrCreate(['name' => $name]);
        }
    }
}
