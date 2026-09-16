<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class TransactionLineSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Sample data can only be seeded locally or in tests.');
        }

        TransactionLine::factory()->create();
    }
}
