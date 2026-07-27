<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentReschedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentReschedule>
 */
class AppointmentRescheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $previous = fake()->dateTimeBetween('-1 month', '-1 day');
        $new = fake()->dateTimeBetween('+1 day', '+1 month');

        return [
            'appointment_id' => Appointment::factory(),
            'previous_scheduled_at' => $previous,
            'new_scheduled_at' => $new,
            'initiated_by' => fake()->randomElement(['patient', 'clinic']),
            'actor_id' => null,
            'reason_category' => null,
            'reason_details' => null,
            'rescheduled_at' => fake()->dateTimeBetween('-1 week', 'now'),
            'notified_at' => null,
        ];
    }

    public function clinicInitiated(): static
    {
        return $this->state(fn () => [
            'initiated_by' => 'clinic',
            'reason_category' => fake()->randomElement([
                'patient_requested',
                'optometrist_unavailable',
                'clinic_schedule_change',
                'scheduling_conflict',
                'other',
            ]),
        ]);
    }

    public function patientInitiated(): static
    {
        return $this->state(fn () => [
            'initiated_by' => 'patient',
            'reason_category' => null,
        ]);
    }
}
