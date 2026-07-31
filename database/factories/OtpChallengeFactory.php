<?php

namespace Database\Factories;

use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<OtpChallenge>
 */
class OtpChallengeFactory extends Factory
{
    protected $model = OtpChallenge::class;

    public function definition(): array
    {
        $code = fake()->numerify('######');

        return [
            'public_id' => (string) Str::uuid(),
            'user_id' => null,
            'purpose' => OtpPurpose::Registration,
            'channel' => 'email',
            'encrypted_destination' => fake()->safeEmail(),
            'destination_hash' => hash('sha256', fake()->safeEmail()),
            'code_digest' => Hash::make($code),
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
            'last_sent_at' => now(),
            'consumed_at' => null,
            'invalidated_at' => null,
            'delivery_status' => 'sent',
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function purpose(OtpPurpose $purpose): static
    {
        return $this->state(['purpose' => $purpose]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinutes(5)]);
    }

    public function consumed(): static
    {
        return $this->state(['consumed_at' => now()]);
    }

    public function invalidated(): static
    {
        return $this->state(['invalidated_at' => now()]);
    }

    public function exhausted(): static
    {
        return $this->state([
            'attempts' => 5,
            'max_attempts' => 5,
            'invalidated_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state([
            'attempts' => 0,
            'consumed_at' => null,
            'invalidated_at' => null,
            'expires_at' => now()->addMinutes(10),
        ]);
    }
}
