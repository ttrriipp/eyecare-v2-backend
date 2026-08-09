<?php

namespace Database\Factories;

use App\Enums\CommercialItemKind;
use App\Enums\TransactionItemType;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobOrderItem>
 */
class JobOrderItemFactory extends Factory
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
            'job_order_id' => JobOrder::factory(),
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $quantity * $unitPrice,
            'product_variant_id' => null,
            'lens_category_id' => null,
            'item_type' => TransactionItemType::Product,
            'item_kind' => CommercialItemKind::CustomProduct,
        ];
    }

    public function product(): static
    {
        return $this->state(fn (array $attributes): array => [
            'item_type' => TransactionItemType::Product,
            'item_kind' => CommercialItemKind::CustomProduct,
        ]);
    }

    public function service(): static
    {
        return $this->state(fn (array $attributes): array => [
            'item_type' => TransactionItemType::Service,
            'item_kind' => CommercialItemKind::Service,
        ]);
    }

    public function frame(): static
    {
        return $this->state(fn (array $attributes): array => [
            'item_type' => TransactionItemType::Product,
            'item_kind' => CommercialItemKind::Frame,
        ]);
    }

    public function lensPackage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'item_type' => TransactionItemType::Product,
            'item_kind' => CommercialItemKind::LensPackage,
        ]);
    }
}
