<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use App\Models\VisitReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => User::factory()->customer(),
            'visit_reason_id' => VisitReason::factory(),
            'appointment_status_id' => $this->pendingStatusId(),
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+1 month'),
            'contact_notes' => fake()->optional()->sentence(),
            'staff_notes' => null,
        ];
    }

    private function pendingStatusId(): int
    {
        return AppointmentStatus::query()->firstOrCreate([
            'name' => 'pending',
        ])->id;
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'appointment_status_id' => AppointmentStatus::query()->firstOrCreate([
                'name' => 'completed',
            ])->id,
        ]);
    }
}
