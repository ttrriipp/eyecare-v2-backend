<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PhysicalChartEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalChartEvent>
 */
class PhysicalChartEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'encounter_id' => null,
            'event_type' => fake()->randomElement(['checkout', 'return', 'relocation', 'copy']),
            'actor_id' => User::factory()->staff(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function checkout(): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_type' => 'checkout',
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_type' => 'return',
        ]);
    }
}
