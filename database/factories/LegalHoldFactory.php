<?php

namespace Database\Factories;

use App\Models\LegalHold;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalHold>
 */
class LegalHoldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_number' => 'LH-'.fake()->unique()->numerify('####'),
            'description' => fake()->sentence(),
            'reason' => fake()->optional()->sentence(),
            'created_by' => User::factory()->admin(),
            'hold_start_date' => now(),
            'hold_end_date' => null,
            'is_active' => true,
        ];
    }
}
