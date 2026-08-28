<?php

namespace Database\Factories;

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLot>
 */
class InventoryLotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory()
                ->for(Product::factory()->contactLens()),
            'lot_number' => strtoupper(fake()->unique()->bothify('LOT-######')),
            'expires_on' => now()->addMonths(6)->toDateString(),
            'received_quantity' => 10,
            'quantity_on_hand' => 10,
            'received_at' => now(),
            'received_by' => User::factory(),
            'source_reference' => fake()->optional()->bothify('PO-####'),
        ];
    }
}
