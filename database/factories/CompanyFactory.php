<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 1000000),
            'account_number' => fake()->unique()->numerify('ACCT-#####'),
            'name' => fake()->company(),
            'sales_rep_id' => 974,
            'is_active' => true,
            'netsuite_updated_at' => '2026-09-01 12:00:00',
            'raw_payload' => [],
        ];
    }
}
