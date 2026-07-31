<?php

namespace Database\Factories;

use App\Enums\PatientInvitationStatus;
use App\Models\Patient;
use App\Models\PatientInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<PatientInvitation>
 */
class PatientInvitationFactory extends Factory
{
    protected $model = PatientInvitation::class;

    public function definition(): array
    {
        $email = fake()->safeEmail();

        return [
            'public_id' => (string) Str::uuid(),
            'patient_id' => Patient::factory(),
            'sender_id' => User::factory(),
            'channel' => 'email',
            'encrypted_destination' => $email,
            'destination_hash' => hash('sha256', $email),
            'secret_digest' => Hash::make(Str::random(32)),
            'status' => PatientInvitationStatus::Pending,
            'expires_at' => now()->addDays(7),
            'sent_at' => now(),
            'revoked_at' => null,
            'accepted_at' => null,
            'accepted_by_user_id' => null,
            'failed_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state([
            'status' => PatientInvitationStatus::Accepted,
            'accepted_at' => now(),
            'accepted_by_user_id' => User::factory(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => PatientInvitationStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state([
            'status' => PatientInvitationStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }
}
