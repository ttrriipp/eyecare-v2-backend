<?php

namespace Database\Factories;

use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrameReservationItem>
 */
class FrameReservationItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'frame_reservation_id' => FrameReservation::factory(),
            'product_variant_id' => ProductVariant::factory(),
        ];
    }
}
