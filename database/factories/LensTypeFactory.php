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
            'price' => fake()->optional(0.8)->randomFloat(2, 500, 8000),
        ];
    }
}
