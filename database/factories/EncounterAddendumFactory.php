<?php

namespace Database\Factories;

use App\Enums\EncounterAddendumType;
use App\Models\Encounter;
use App\Models\EncounterAddendum;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EncounterAddendum>
 */
class EncounterAddendumFactory extends Factory
{
    protected $model = EncounterAddendum::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory(),
            'sequence_number' => 1,
            'type' => EncounterAddendumType::Correction,
            'reason' => fake()->sentence(),
            'content' => fake()->paragraph(),
            'authored_by' => User::factory()->optometrist(),
            'authored_at' => now(),
        ];
    }

    public function supplement(): static
    {
        return $this->state([
            'type' => EncounterAddendumType::Supplement,
        ]);
    }
}
