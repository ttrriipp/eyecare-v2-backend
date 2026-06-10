<?php

namespace Database\Factories;

use App\Models\BillingStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingStatus>
 */
class BillingStatusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
        ];
    }
}
