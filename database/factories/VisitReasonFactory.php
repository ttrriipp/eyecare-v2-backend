<?php

namespace Database\Factories;

use App\Models\VisitReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitReason>
 */
class VisitReasonFactory extends Factory
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
            'duration_minutes' => fake()->randomElement([15, 20, 30, 45, 60]),
        ];
    }
}
