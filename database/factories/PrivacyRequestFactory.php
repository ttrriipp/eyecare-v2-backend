<?php

namespace Database\Factories;

use App\Enums\PrivacyRequestDisposition;
use App\Enums\PrivacyRequestType;
use App\Models\Patient;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivacyRequest>
 */
class PrivacyRequestFactory extends Factory
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
            'requester_user_id' => User::factory()->patient(),
            'request_type' => fake()->randomElement(PrivacyRequestType::cases()),
            'identity_verified_method' => fake()->randomElement(['in_person', 'id_document', 'email_verification']),
            'description' => fake()->sentence(),
            'disposition' => PrivacyRequestDisposition::Pending,
            'disposition_reason' => null,
            'handled_by' => null,
            'requested_at' => now(),
            'handled_at' => null,
        ];
    }
}
