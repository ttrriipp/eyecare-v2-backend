<?php

namespace Database\Factories;

use App\Models\InventoryLot;
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
        $receivedQuantity = fake()->numberBetween(10, 100);

        return [
            'product_variant_id' => ProductVariant::factory(),
            'lot_number' => strtoupper(fake()->unique()->bothify('LOT-####-??')),
            'expires_on' => fake()->dateTimeBetween('+6 months', '+2 years'),
            'received_quantity' => $receivedQuantity,
            'quantity_on_hand' => $receivedQuantity,
            'received_at' => fake()->dateTimeBetween('-30 days', '-1 day'),
            'received_by' => User::factory(),
            'source_reference' => fake()->optional()->bothify('PO-####'),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_on' => fake()->dateTimeBetween('-2 years', '-1 day'),
        ]);
    }

    public function nearExpiry(int $days = 30): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_on' => fake()->dateTimeBetween('now', "+{$days} days"),
        ]);
    }

    public function depleted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'quantity_on_hand' => 0,
        ]);
    }
}
