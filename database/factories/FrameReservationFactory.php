<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\FrameReservation;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrameReservation>
 */
class FrameReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => Appointment::factory(),
            'accepted_at' => null,
            'staff_notes' => null,
        ];
    }

    public function forAppointment(Appointment $appointment): static
    {
        return $this->state(fn (array $attributes): array => [
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
        ]);
    }
}
