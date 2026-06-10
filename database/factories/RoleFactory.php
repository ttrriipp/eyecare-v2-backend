<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
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

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'admin',
        ]);
    }

    public function staff(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'staff',
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'customer',
        ]);
    }
}
