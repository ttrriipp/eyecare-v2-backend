<?php

namespace Database\Factories;

use App\Models\PilotParticipantAccount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PilotParticipantAccount>
 */
class PilotParticipantAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->afterCreating(function (User $user): void {
                $user->roles()->syncWithoutDetaching(
                    Role::query()->where('name', Role::Patient)->pluck('id'),
                );
            }),
            'participant_code' => strtoupper(fake()->unique()->bothify('PILOT-####??')),
            'expires_at' => now()->addMonth(),
            'revoked_at' => null,
        ];
    }
}
