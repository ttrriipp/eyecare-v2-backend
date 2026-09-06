<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     *
     * Schema-changing tests can commit a prior test's transaction and reset
     * Faker's in-memory unique registry. A UUID keeps the database-level
     * unique constraint safe across those boundaries.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().'-'.Str::uuid(),
        ];
    }
}
