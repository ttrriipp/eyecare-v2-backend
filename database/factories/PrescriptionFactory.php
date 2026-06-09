<?php

namespace Database\Factories;

use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $prescribedAt = fake()->dateTimeBetween('-1 year', 'now');

        return [
            'customer_id' => User::factory()->customer(),
            'appointment_id' => null,
            'previous_prescription_id' => null,
            'created_by' => User::factory()->staff(),
            'od_sphere' => fake()->randomFloat(2, -6, 2),
            'od_cylinder' => fake()->randomFloat(2, -2, 0),
            'od_axis' => fake()->numberBetween(1, 180),
            'od_add' => fake()->optional()->randomFloat(2, 0.75, 2.5),
            'od_prism' => null,
            'od_base' => null,
            'os_sphere' => fake()->randomFloat(2, -6, 2),
            'os_cylinder' => fake()->randomFloat(2, -2, 0),
            'os_axis' => fake()->numberBetween(1, 180),
            'os_add' => fake()->optional()->randomFloat(2, 0.75, 2.5),
            'os_prism' => null,
            'os_base' => null,
            'pd' => fake()->randomFloat(1, 58, 68),
            'prescribed_at' => $prescribedAt,
            'expires_at' => fake()->dateTimeBetween($prescribedAt, '+2 years'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
