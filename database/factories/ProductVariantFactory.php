<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->colorName(),
            'sku' => strtoupper(fake()->unique()->bothify('FRM-####-??')),
            'is_active' => true,
            'price' => fake()->randomFloat(2, 50, 500),
            'attributes' => [
                'temple' => fake()->numberBetween(135, 150),
            ],
            'stock_quantity' => fake()->numberBetween(0, 25),
            'low_stock_threshold' => 3,
            'ar_eligible' => false,
            'ar_asset_reference' => null,
        ];
    }

    public function arEligible(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ar_eligible' => true,
            'ar_asset_reference' => 'frames/'.fake()->uuid().'.glb',
        ]);
    }
}
