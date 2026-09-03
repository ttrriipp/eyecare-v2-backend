<?php

namespace Database\Factories;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Encounter
 */
class EncounterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_number' => 'CON-'.now()->format('Y').'-'.str_pad(fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'patient_id' => Patient::factory(),
            'appointment_id' => null,
            'optometrist_id' => null,
            'status' => EncounterStatus::Planned,
            'started_at' => null,
            'completed_at' => null,
            'findings' => null,
            'remarks' => null,
            'chief_complaint' => null,
            'past_ocular_history' => null,
            'past_surgical_history' => null,
            'past_medical_history' => null,
            'allergies' => null,
            'medications' => null,
            'plan' => null,
            'assessment' => null,
            'supporting_test_results' => null,
            'last_wizard_step' => null,
            'draft_saved_at' => null,
            'completed_by' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EncounterStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EncounterStatus::Completed,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }
}
