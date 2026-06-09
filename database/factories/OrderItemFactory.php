<?php

namespace Database\Factories;

use App\Models\LensType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $variant = ProductVariant::factory()->create();
        $variant->load('product');
        $lensType = LensType::factory()->create();
        $quantity = fake()->numberBetween(1, 2);
        $unitPrice = $variant->price;
        $subtotal = bcmul((string) $unitPrice, (string) $quantity, 2);

        return [
            'order_id' => Order::factory(),
            'product_variant_id' => $variant->id,
            'lens_type_id' => $lensType->id,
            'product_id' => $variant->product_id,
            'product_name' => $variant->product->name,
            'variant_name' => $variant->name,
            'variant_sku' => $variant->sku,
            'lens_type_name' => $lensType->name,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
        ];
    }
}
