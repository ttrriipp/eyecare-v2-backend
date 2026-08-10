<?php

namespace Database\Factories;

use App\Models\LensOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LensOption>
 */
class LensOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 100, 2500),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
