<?php

namespace Database\Factories;

use App\Enums\AppointmentRescheduleRequestStatus;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentRescheduleRequest>
 */
class AppointmentRescheduleRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $scheduledAt = fake()->dateTimeBetween('+1 day', '+1 month');
        $requestedAt = fake()->dateTimeBetween('+1 day', '+1 month');

        return [
            'request_number' => null,
            'appointment_id' => Appointment::factory()->state([
                'scheduled_at' => $scheduledAt,
            ]),
            'user_id' => User::factory(),
            'patient_id' => Patient::factory(),
            'current_scheduled_at' => $scheduledAt,
            'requested_scheduled_at' => $requestedAt,
            'alternative_scheduled_times' => [],
            'encrypted_reason_details' => fake()->optional()->sentence(),
            'status' => AppointmentRescheduleRequestStatus::Pending,
            'selected_scheduled_at' => null,
            'resolved_by_user_id' => null,
            'resolved_at' => null,
            'rejection_reason' => null,
            'expires_at' => $scheduledAt,
            'appointment_reschedule_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => AppointmentRescheduleRequestStatus::Pending,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => AppointmentRescheduleRequestStatus::Approved,
            'selected_scheduled_at' => fake()->dateTimeBetween('+1 day', '+1 month'),
            'resolved_at' => now(),
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => AppointmentRescheduleRequestStatus::Rejected,
            'resolved_at' => now(),
            'rejection_reason' => fake()->sentence(),
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => AppointmentRescheduleRequestStatus::Cancelled,
            'resolved_at' => now(),
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => AppointmentRescheduleRequestStatus::Expired,
            'resolved_at' => now(),
            'expires_at' => now()->subMinute(),
        ]);
    }
}
