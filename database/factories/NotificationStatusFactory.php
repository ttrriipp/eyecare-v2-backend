<?php

namespace Database\Factories;

use App\Models\NotificationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationStatus>
 */
class NotificationStatusFactory extends Factory
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
