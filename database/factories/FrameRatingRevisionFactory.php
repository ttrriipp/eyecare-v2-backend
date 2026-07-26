<?php

namespace Database\Factories;

use App\Models\FrameRating;
use App\Models\FrameRatingRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrameRatingRevision>
 */
class FrameRatingRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'frame_rating_id' => FrameRating::factory(),
            'revision_number' => 1,
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional()->sentence(),
            'revised_by' => User::factory()->patient(),
            'revised_at' => now(),
        ];
    }
}
