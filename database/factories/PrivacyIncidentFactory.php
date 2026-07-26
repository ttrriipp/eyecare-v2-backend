<?php

namespace Database\Factories;

use App\Enums\IncidentStatus;
use App\Models\PrivacyIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PrivacyIncident>
 */
class PrivacyIncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_number' => 'INC-'.Str::ulid(),
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'scope' => fake()->optional()->sentence(),
            'status' => IncidentStatus::Reported,
            'reported_by' => User::factory()->admin(),
            'assigned_to' => null,
            'containment_actions' => null,
            'decisions' => null,
            'resolution_notes' => null,
            'discovered_at' => now(),
            'contained_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }
}
