<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     *
     * Schema-changing tests can commit a prior test's transaction and reset
     * Faker's in-memory unique registry. A UUID keeps the database-level
     * unique constraint safe across those boundaries.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word().'-'.Str::uuid(),
        ];
    }
}
