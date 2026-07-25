<?php

namespace Database\Factories;

use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationItem>
 */
class QuotationItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 3);
        $unitPrice = fake()->randomFloat(2, 50, 5000);

        return [
            'quotation_revision_id' => QuotationRevision::factory(),
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $quantity * $unitPrice,
            'product_variant_id' => null,
            'lens_category_id' => null,
        ];
    }
}
