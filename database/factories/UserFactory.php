<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\ProviderHour;
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
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'first_name' => $firstName,
            'middle_name' => fake()->optional(0.3)->firstName(),
            'last_name' => $lastName,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09#########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => $this->fixedRoleId('patient'),
            'is_optometrist' => false,
            'is_active' => true,
            'must_change_password' => false,
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
        ])->afterCreating(function (User $user): void {
            if ($user->patient === null) {
                Patient::factory()->create(['user_id' => $user->id]);
            }
            $user->load('patient');
        });
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
        ])->afterCreating(function (User $user): void {
            // Create provider hours for all weekdays if none exist
            if ($user->providerHours()->count() === 0) {
                foreach (range(0, 6) as $weekday) {
                    ProviderHour::factory()->create([
                        'user_id' => $user->id,
                        'weekday' => $weekday,
                        'start_time' => '09:00',
                        'end_time' => '17:00',
                        'enabled' => true,
                    ]);
                }
            }
        });
    }

    /**
     * Walk-in patient: structured name + phone only, no email, no password. Cannot log in to the API.
     */
    public function walkIn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
            'remember_token' => null,
            'role_id' => $this->fixedRoleId('patient'),
        ])->afterCreating(function (User $user): void {
            if ($user->patient === null) {
                Patient::factory()->walkIn()->create(['user_id' => $user->id]);
            }
            $user->load('patient');
        });
    }

    private function fixedRoleId(string $name): int
    {
        return Role::query()->firstOrCreate([
            'name' => $name,
        ])->id;
    }
}
