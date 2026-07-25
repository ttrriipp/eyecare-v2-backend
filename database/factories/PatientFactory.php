<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'full_name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-5 years'),
            'occupation' => fake()->optional()->jobTitle(),
            'address' => fake()->streetAddress(),
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'contact_email' => fake()->safeEmail(),
            'phone' => fake()->numerify('09#########'),
        ];
    }

    public function linked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => User::factory()->patient(),
        ]);
    }

    public function linkedTo(User $account): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $account->getKey(),
        ]);
    }

    public function unlinked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
        ]);
    }

    public function walkIn(): static
    {
        return $this->unlinked()->state(fn (array $attributes): array => [
            'date_of_birth' => null,
            'occupation' => null,
            'address' => null,
            'gender' => null,
            'contact_email' => null,
        ]);
    }
}
