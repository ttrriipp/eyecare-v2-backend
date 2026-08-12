<?php

namespace Database\Factories;

use App\Models\FrameRating;
use App\Models\Patient;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrameRating>
 */
class FrameRatingFactory extends Factory
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
            'product_variant_id' => ProductVariant::factory(),
            'dispensing_event_id' => null,
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional()->sentence(),
        ];
    }
}
