<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientLinkRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientLinkRequest>
 */
class PatientLinkRequestFactory extends Factory
{
    protected $model = PatientLinkRequest::class;

    public function definition(): array
    {
        return [
            'request_number' => null, // auto-generated
            'user_id' => User::factory()->patient(),
            'encrypted_identity_snapshot' => ['first_name' => 'Test', 'last_name' => 'User'],
            'status' => 'pending',
            'reviewed_patient_id' => null,
            'reviewer_id' => null,
            'decision_note' => null,
            'reviewed_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(function () {
            return [
                'status' => 'approved',
                'reviewed_patient_id' => Patient::factory(),
                'reviewer_id' => User::factory(),
                'reviewed_at' => now(),
            ];
        });
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => 'rejected',
            'reviewer_id' => User::factory(),
            'decision_note' => 'No matching patient found',
            'reviewed_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state([
            'status' => 'pending',
            'reviewed_patient_id' => null,
            'reviewer_id' => null,
            'reviewed_at' => null,
        ]);
    }
}
