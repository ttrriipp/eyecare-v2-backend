<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Appointment;
use App\Models\FrameReservation;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrameReservation>
 */
class FrameReservationFactory extends Factory
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
            'appointment_id' => null,
            'status' => ReservationStatus::Requested,
            'staff_notes' => null,
            'expires_at' => null,
        ];
    }

    public function forAppointment(Appointment $appointment): static
    {
        return $this->state(fn (array $attributes): array => [
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
        ]);
    }

    public function prepared(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReservationStatus::Prepared,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReservationStatus::Cancelled,
        ]);
    }
}
