<?php

namespace Database\Factories;

use App\Models\ClinicHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicHour>
 */
class ClinicHourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weekday' => fake()->numberBetween(0, 6),
            'open_time' => '09:00',
            'close_time' => '17:00',
            'enabled' => true,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
        ]);
    }

    public function forWeekday(int $weekday): static
    {
        return $this->state(fn (array $attributes): array => [
            'weekday' => $weekday,
        ]);
    }
}
