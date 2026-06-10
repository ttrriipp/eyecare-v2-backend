<?php

namespace Database\Factories;

use App\Models\AppointmentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentStatus>
 */
class AppointmentStatusFactory extends Factory
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
