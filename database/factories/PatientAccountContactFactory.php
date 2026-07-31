<?php

namespace Database\Factories;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Models\PatientAccountContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientAccountContact>
 */
class PatientAccountContactFactory extends Factory
{
    protected $model = PatientAccountContact::class;

    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();
        $lookupHash = app(CreateContactLookupHash::class)->forEmail($email);

        return [
            'user_id' => User::factory()->patient(),
            'type' => 'email',
            'encrypted_value' => $email,
            'lookup_hash' => $lookupHash,
            'verified_at' => null,
            'is_primary' => false,
        ];
    }

    public function email(?string $value = null): static
    {
        return $this->state(function (array $attributes) use ($value) {
            $email = $value ?? fake()->unique()->safeEmail();

            return [
                'type' => 'email',
                'encrypted_value' => $email,
                'lookup_hash' => app(CreateContactLookupHash::class)->forEmail($email),
            ];
        });
    }

    public function phone(?string $value = null): static
    {
        return $this->state(function (array $attributes) use ($value) {
            $phone = $value ?? '+639'.fake()->numerify('#########');

            return [
                'type' => 'phone',
                'encrypted_value' => $phone,
                'lookup_hash' => app(CreateContactLookupHash::class)->forPhone($phone),
            ];
        });
    }

    public function verified(): static
    {
        return $this->state([
            'verified_at' => now(),
        ]);
    }

    public function primary(): static
    {
        return $this->state([
            'is_primary' => true,
            'verified_at' => now(),
        ]);
    }
}
