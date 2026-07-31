<?php

namespace Database\Factories;

use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentRequest>
 */
class AppointmentRequestFactory extends Factory
{
    protected $model = AppointmentRequest::class;

    public function definition(): array
    {
        return [
            'request_number' => null, // auto-generated
            'user_id' => User::factory()->patient(),
            'patient_id' => null,
            'appointment_type_id' => null,
            'appointment_id' => null,
            'scheduled_at' => now()->addDay(),
            'provisional_duration_minutes' => 30,
            'encrypted_reason_for_visit' => fake()->sentence(),
            'encrypted_identity_snapshot' => null,
            'status' => AppointmentRequestStatus::Pending,
            'expires_at' => now()->addHours(24),
            'resolved_by_user_id' => null,
            'resolved_at' => null,
        ];
    }

    public function linked(): static
    {
        return $this->state(function () {
            return [
                'patient_id' => User::factory()->patient()->create()->patient->id,
            ];
        });
    }

    public function accepted(): static
    {
        return $this->state([
            'status' => AppointmentRequestStatus::Accepted,
            'resolved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => AppointmentRequestStatus::Rejected,
            'resolved_by_user_id' => User::factory(),
            'resolved_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => AppointmentRequestStatus::Cancelled,
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => AppointmentRequestStatus::Expired,
            'expires_at' => now()->subHour(),
        ]);
    }
}
