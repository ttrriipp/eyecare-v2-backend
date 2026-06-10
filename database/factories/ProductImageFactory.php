<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
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
            'product_variant_id' => null,
            'path' => 'products/'.fake()->uuid().'.jpg',
            'sort_order' => 0,
            'is_primary' => true,
        ];
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
        ]);
    }
}
