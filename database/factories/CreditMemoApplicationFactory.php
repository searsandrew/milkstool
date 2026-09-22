<?php

namespace Database\Factories;

use App\Models\CreditMemoApplication;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditMemoApplication> */
class CreditMemoApplicationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['transaction_id' => Transaction::factory()->state(['type' => 'CustCred']),
            'credit_line_id' => 1, 'target_netsuite_id' => fake()->unique()->numberBetween(1000, 999999),
            'target_line_id' => 0, 'target_customer_id' => 16, 'target_currency_id' => 1,
            'target_type' => 'CustInvc', 'foreign_amount' => '100.00000000', 'raw_payload' => []];
    }
}
