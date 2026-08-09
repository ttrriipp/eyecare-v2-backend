<?php

namespace Database\Factories;

use App\Models\AppointmentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentType>
 */
class AppointmentTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'patient_label' => null,
            'patient_description' => null,
            'duration_minutes' => fake()->randomElement([15, 20, 30, 45, 60]),
            'requires_referral' => false,
            'is_active' => true,
            'is_patient_visible' => true,
        ];
    }

    public function referral(): static
    {
        return $this->state(fn (array $attributes): array => [
            'requires_referral' => true,
        ]);
    }

    public function internalOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_patient_visible' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
