<?php

namespace Database\Factories;

use App\Enums\CommercialItemKind;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessoryOrderRequestItem>
 */
class AccessoryOrderRequestItemFactory extends Factory
{
    protected $model = AccessoryOrderRequestItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'accessory_order_request_id' => AccessoryOrderRequest::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'description' => $this->faker->word(),
            'quantity' => $this->faker->numberBetween(1, 5),
            'unit_price' => $this->faker->numberBetween(100, 5000),
            'amount' => fn (array $attributes) => $attributes['quantity'] * $attributes['unit_price'],
            'item_kind' => CommercialItemKind::Accessory,
        ];
    }
}
