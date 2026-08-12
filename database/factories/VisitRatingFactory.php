<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Models\VisitRating;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitRating>
 */
class VisitRatingFactory extends Factory
{
    protected $model = VisitRating::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => Appointment::factory()->fulfilled(),
            'encounter_id' => null,
            'optometrist_id' => null,
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional(0.7)->sentence(),
            'service_ids' => null,
            'is_hidden' => false,
            'moderation_reason' => null,
            'moderated_by' => null,
            'moderated_at' => null,
        ];
    }

    /**
     * Indicate the rating is hidden.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_hidden' => true,
            'moderation_reason' => 'Inappropriate content',
            'moderated_by' => User::factory(),
            'moderated_at' => now(),
        ]);
    }
}
