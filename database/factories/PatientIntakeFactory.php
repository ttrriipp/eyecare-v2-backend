<?php

namespace Database\Factories;

use App\Enums\IntakeStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PatientIntake;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends PatientIntake
 */
class PatientIntakeFactory extends Factory
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
            'appointment_id' => null,
            'status' => IntakeStatus::Draft,
            'appointment_type' => fake()->randomElement(['New Patient', 'Follow-up', 'Routine Check-up', 'Referral']),
            'full_name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-5 years'),
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'occupation' => fake()->optional()->jobTitle(),
            'address' => fake()->optional()->streetAddress(),
            'phone' => fake()->numerify('09#########'),
            'email' => fake()->optional()->safeEmail(),
            'chief_complaint' => fake()->optional()->sentence(),
            'past_ocular_history' => fake()->optional()->sentence(),
            'past_surgical_history' => null,
            'past_medical_history' => fake()->optional()->sentence(),
            'allergies' => fake()->optional()->word(),
            'medications' => null,
            'submitted_by' => null,
            'submitted_at' => null,
            'verified_by' => null,
            'verified_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => IntakeStatus::Submitted,
            'submitted_by' => User::factory()->patient(),
            'submitted_at' => fake()->dateTimeBetween('-7 days', '-1 day'),
        ]);
    }

    public function verified(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => IntakeStatus::Verified,
            'verified_by' => User::factory()->staff(),
            'verified_at' => fake()->dateTimeBetween('-1 day', 'now'),
        ]);
    }

    public function linkedToAppointment(Appointment $appointment): static
    {
        return $this->state(fn (array $attributes): array => [
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
        ]);
    }
}
