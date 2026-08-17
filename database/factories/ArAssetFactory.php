<?php

namespace Database\Factories;

use App\Enums\ArAssetStatus;
use App\Models\ArAsset;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArAsset>
 */
class ArAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'version' => 1,
            'status' => ArAssetStatus::Validated,
            'format' => 'glb',
            'quarantine_path' => 'quarantine/'.fake()->uuid.'.glb',
            'published_path' => null,
            'url' => null,
            'byte_size' => 0,
            'sha256' => hash('sha256', ''),
            'calibration' => [
                'frame_width_mm' => 123.0,
                'outer_frame_height_mm' => 48.0,
                'lens_width_mm' => 50.0,
                'lens_height_mm' => 45.0,
                'bridge_width_mm' => 20.0,
                'temple_length_mm' => 140.0,
                'scale' => ['x' => 0.123, 'y' => 0.144565, 'z' => 0.123],
                'anchor' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
                'rotation_degrees' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            ],
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ArAssetStatus::Published,
        ]);
    }
}
