<?php

namespace Database\Factories;

use App\Models\DiscountType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountType>
 */
class DiscountTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'type' => fake()->randomElement(['percentage', 'fixed']),
            'value' => fake()->randomFloat(2, 1, 50),
            'is_active' => true,
        ];
    }

    public function percentage(float $value): static
    {
        return $this->state(['type' => 'percentage', 'value' => $value]);
    }

    public function fixed(float $value): static
    {
        return $this->state(['type' => 'fixed', 'value' => $value]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
