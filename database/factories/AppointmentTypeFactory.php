<?php

namespace Database\Factories;

use App\Models\AppointmentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentType>
 */
class AppointmentTypeFactory extends Factory
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
            'requires_referral' => false,
            'is_active' => true,
        ];
    }

    public function referral(): static
    {
        return $this->state(fn (array $attributes): array => [
            'requires_referral' => true,
        ]);
    }
}
