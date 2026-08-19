<?php

namespace Database\Factories;

use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
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
            'request_number' => null,
            'user_id' => User::factory()->patient(),
            'patient_id' => null,
            'appointment_type_id' => AppointmentType::factory(),
            'appointment_id' => null,
            'scheduled_at' => now()->addDay(),
            'alternative_scheduled_times' => null,
            'provisional_duration_minutes' => 30,
            'encrypted_reason_for_visit' => fake()->sentence(),
            'encrypted_referring_source' => null,
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
            'resolved_by_user_id' => User::factory()->staff(),
            'resolved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => AppointmentRequestStatus::Rejected,
            'resolved_by_user_id' => User::factory()->staff(),
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
        // expires_at is always the latest of the submitted preferred times
        // (see SubmitAppointmentRequest::calculateExpiry), so an expired
        // request's scheduled_at must be in the past too — it can never
        // outlive its own expiry.
        return $this->state([
            'status' => AppointmentRequestStatus::Expired,
            'scheduled_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
        ]);
    }

    public function withSnapshot(): static
    {
        return $this->state([
            'encrypted_identity_snapshot' => [
                'phone' => '+639171234567',
                'email' => null,
                'first_name' => fake()->firstName(),
                'middle_name' => fake()->optional(0.3)->firstName(),
                'last_name' => fake()->lastName(),
                'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
                'gender' => fake()->randomElement(['male', 'female', 'other']),
                'occupation' => fake()->jobTitle(),
                'address' => fake()->address(),
                'verified_contact_type' => 'phone',
                'verified_contact_masked' => '091***4567',
                'verified_contact_hash' => hash('sha256', '09171234567'),
                'submitted_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function withAlternatives(): static
    {
        return $this->state([
            'alternative_scheduled_times' => [
                now()->addDay()->setTime(10, 30)->toISOString(),
                now()->addDays(2)->setTime(9, 0)->toISOString(),
            ],
        ]);
    }

    public function legacy(): static
    {
        return $this->state([
            'appointment_type_id' => null,
            'alternative_scheduled_times' => null,
            'encrypted_referring_source' => null,
        ]);
    }
}
