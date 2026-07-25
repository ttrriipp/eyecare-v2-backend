<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09#########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => $this->fixedRoleId('patient'),
            'is_optometrist' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role_id' => $this->fixedRoleId('admin'),
        ]);
    }

    public function staff(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role_id' => $this->fixedRoleId('staff'),
        ]);
    }

    public function patient(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role_id' => $this->fixedRoleId('patient'),
        ]);
    }

    /**
     * Temporary compatibility state for legacy tests pending direct-order removal.
     */
    public function customer(): static
    {
        return $this->patient();
    }

    public function optometrist(): static
    {
        return $this->staff()->state(fn (array $attributes): array => [
            'is_optometrist' => true,
        ]);
    }

    /**
     * Walk-in patient: name + phone only, no email, no password. Cannot log in to the API.
     */
    public function walkIn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
            'remember_token' => null,
            'role_id' => $this->fixedRoleId('patient'),
        ]);
    }

    private function fixedRoleId(string $name): int
    {
        return Role::query()->firstOrCreate([
            'name' => $name,
        ])->id;
    }
}
