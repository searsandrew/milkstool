<?php

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Item> */
class ItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 1000000),
            'item_number' => fake()->unique()->bothify('ITEM-####'),
            'description' => fake()->sentence(),
            'upc' => fake()->numerify('00##########'),
            'netsuite_updated_at' => '2026-11-18 12:00:00',
            'synced_at' => now(),
        ];
    }
}
