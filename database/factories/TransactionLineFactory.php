<?php

namespace Database\Factories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransactionLine> */
class TransactionLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'netsuite_line_id' => fake()->unique()->numberBetween(1, 1000000),
            'item_id' => 10,
            'item_number' => 'ITEM-10',
            'quantity' => '-2.00000000',
            'rate' => '50.00000000',
            'amount' => '-100.00000000',
            'is_mainline' => false,
            'is_tax_line' => false,
            'is_discount_line' => false,
            'raw_payload' => [],
        ];
    }
}
