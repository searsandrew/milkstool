<?php

namespace Database\Factories;

use App\Models\ApiClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApiClient> */
class ApiClientFactory extends Factory
{
    /** @return array{name: string} */
    public function definition(): array
    {
        return ['name' => fake()->unique()->slug(2)];
    }
}
