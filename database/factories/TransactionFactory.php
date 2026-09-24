<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 1000000),
            'company_id' => Company::factory(),
            'type' => 'SalesOrd',
            'number' => fake()->unique()->numerify('SO#####'),
            'transaction_date' => '2026-09-01',
            'status' => 'B',
            'currency_id' => 1,
            'total' => '100.00',
            'foreign_total' => '100.00',
            'netsuite_updated_at' => '2026-09-01 12:00:00',
            'synced_at' => '2026-09-01 12:00:00',
            'raw_payload' => [],
        ];
    }
}
