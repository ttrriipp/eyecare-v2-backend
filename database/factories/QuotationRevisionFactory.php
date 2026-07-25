<?php

namespace Database\Factories;

use App\Models\Quotation;
use App\Models\QuotationRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationRevision>
 */
class QuotationRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_id' => Quotation::factory(),
            'revision_number' => 1,
            'subtotal' => fake()->randomFloat(2, 100, 10000),
            'discount_amount' => 0,
            'total' => fn (array $attributes): float => $attributes['subtotal'] - $attributes['discount_amount'],
            'notes' => null,
            'presented_by' => null,
            'presented_at' => null,
            'accepted_by' => null,
            'accepted_at' => null,
        ];
    }
}
