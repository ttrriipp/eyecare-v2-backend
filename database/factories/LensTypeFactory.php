<?php

namespace Database\Factories;

use App\Models\LensType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LensType>
 */
class LensTypeFactory extends Factory
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
            'description' => fake()->optional()->sentence(),
            'price' => null,
        ];
    }

    public function withPrice(?float $price = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'price' => $price ?? fake()->randomFloat(2, 500, 8000),
        ]);
    }
}
