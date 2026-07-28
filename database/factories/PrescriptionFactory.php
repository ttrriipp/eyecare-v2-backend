<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\Patient;
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
        return [
            'patient_id' => Patient::factory(),
            'encounter_id' => null,
            'appointment_id' => null,
            'previous_prescription_id' => null,
            'created_by' => User::factory()->optometrist(),
            // Main group
            'main_od_value' => null,
            'main_od_sphere' => fake()->randomFloat(2, -6, 2),
            'main_od_cylinder' => fake()->randomFloat(2, -2, 0),
            'main_os_value' => null,
            'main_os_sphere' => fake()->randomFloat(2, -6, 2),
            'main_os_cylinder' => fake()->randomFloat(2, -2, 0),
            // ADD group (blank by default)
            'add_od_value' => null,
            'add_od_sphere' => null,
            'add_od_cylinder' => null,
            'add_os_value' => null,
            'add_os_sphere' => null,
            'add_os_cylinder' => null,
            'remarks' => fake()->optional()->sentence(),
            'amendment_reason' => null,
            'prescribed_at' => now(),
        ];
    }

    public function mainOnly(): static
    {
        return $this->state(fn () => [
            'add_od_value' => null,
            'add_od_sphere' => null,
            'add_od_cylinder' => null,
            'add_os_value' => null,
            'add_os_sphere' => null,
            'add_os_cylinder' => null,
        ]);
    }

    public function withAddGroup(): static
    {
        return $this->state(fn () => [
            'add_od_value' => null,
            'add_od_sphere' => fake()->randomFloat(2, 0.75, 2.5),
            'add_od_cylinder' => fake()->randomFloat(2, -1, 0),
            'add_os_value' => null,
            'add_os_sphere' => fake()->randomFloat(2, 0.75, 2.5),
            'add_os_cylinder' => fake()->randomFloat(2, -1, 0),
        ]);
    }

    public function linkedToEncounter(Encounter $encounter): static
    {
        return $this->state(fn (array $attributes): array => [
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->id,
            'appointment_id' => $encounter->appointment_id,
        ]);
    }
}
