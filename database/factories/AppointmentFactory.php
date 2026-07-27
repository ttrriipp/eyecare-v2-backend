<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Patient;
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
            'patient_id' => Patient::factory(),
            'appointment_type_id' => AppointmentType::factory(),
            'duration_minutes' => 30,
            'referring_source' => null,
            'created_by' => null,
            'optometrist_id' => null,
            'source' => 'manual',
            'appointment_status_id' => $this->pendingStatusId(),
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+1 month'),
            'checked_in_at' => null,
            'checked_in_by' => null,
            'completed_at' => null,
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
