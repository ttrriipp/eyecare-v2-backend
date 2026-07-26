<?php

namespace Database\Factories;

use App\Models\RetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetentionPolicy>
 */
class RetentionPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => fake()->unique()->word(),
            'description' => fake()->sentence(),
            'retention_days' => fake()->randomElement([365, 730, 1095, 2555]),
            'next_review_date' => fake()->dateTimeBetween('now', '+1 year'),
            'auto_purge_enabled' => false,
            'notes' => null,
        ];
    }
}
