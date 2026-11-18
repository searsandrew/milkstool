<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;
use RuntimeException;

class ItemSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Sample data can only be seeded locally or in tests.');
        }

        Item::factory()->create();
    }
}
