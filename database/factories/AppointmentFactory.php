<?php

namespace Database\Factories;

use App\Enums\AppointmentStatusName;
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
            'appointment_status_id' => $this->statusId(AppointmentStatusName::Scheduled),
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+1 month'),
            'checked_in_at' => null,
            'checked_in_by' => null,
            'fulfilled_at' => null,
            'cancelled_by' => null,
            'cancelled_by_user_id' => null,
            'cancellation_reason_category' => null,
            'cancellation_reason_details' => null,
            'cancelled_at' => null,
            'no_show_by' => null,
            'no_show_at' => null,
            'contact_notes' => fake()->optional()->sentence(),
            'staff_notes' => null,
        ];
    }

    public function fulfilled(): static
    {
        return $this->state(fn () => [
            'appointment_status_id' => $this->statusId(AppointmentStatusName::Fulfilled),
            'fulfilled_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'appointment_status_id' => $this->statusId(AppointmentStatusName::Cancelled),
            'cancelled_by' => 'clinic',
            'cancelled_at' => now(),
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn () => [
            'appointment_status_id' => $this->statusId(AppointmentStatusName::NoShow),
            'no_show_at' => now(),
        ]);
    }

    private function statusId(AppointmentStatusName $status): int
    {
        return AppointmentStatus::query()->firstOrCreate([
            'name' => $status->value,
        ])->id;
    }
}
