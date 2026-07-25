<?php

namespace Database\Factories;

use App\Models\ScheduleOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleOverride>
 */
class ScheduleOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'override_date' => fake()->dateTimeBetween('today', '+30 days'),
            'type' => ScheduleOverride::TYPE_CLOSED,
            'start_time' => null,
            'end_time' => null,
            'reason' => fake()->optional()->sentence(),
        ];
    }

    public function clinicClosed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
            'type' => ScheduleOverride::TYPE_CLOSED,
            'start_time' => null,
            'end_time' => null,
        ]);
    }

    public function earlyClose(string $time = '14:00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
            'type' => ScheduleOverride::TYPE_EARLY_CLOSE,
            'start_time' => $time,
            'end_time' => null,
        ]);
    }

    public function providerAbsence(?User $user = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user?->id ?? User::factory()->optometrist()->create()->id,
            'type' => ScheduleOverride::TYPE_PROVIDER_ABSENCE,
            'start_time' => null,
            'end_time' => null,
        ]);
    }
}
