<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'brand_id' => Brand::factory(),
            'category_id' => ProductCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->optional()->paragraph(),
            'is_active' => true,
            'product_type' => 'frame',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function accessory(): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_type' => 'accessory',
        ]);
    }
}
