<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_user_id' => User::factory()->patient(),
            'patient_id' => Patient::factory(),
        ];
    }

    /**
     * Unlinked general inquiry: account-owned, no Patient.
     */
    public function unlinked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_user_id' => User::factory()->patient(),
            'patient_id' => null,
        ]);
    }

    /**
     * Linked current: account-owned and Patient-associated.
     */
    public function linked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_user_id' => User::factory()->patient(),
            'patient_id' => Patient::factory(),
        ]);
    }

    /**
     * Historical thread after unlink: Patient-only, no account.
     */
    public function historical(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_user_id' => null,
            'patient_id' => Patient::factory(),
        ]);
    }
}
