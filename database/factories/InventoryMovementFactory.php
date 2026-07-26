<?php

namespace Database\Factories;

use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'reservation_id' => null,
            'job_order_id' => null,
            'inventory_movement_type_id' => InventoryMovementType::query()
                ->firstOrCreate(['name' => 'manual_adjustment'])->id,
            'quantity_change' => fake()->numberBetween(-10, 10),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Create a commitment movement linked to a job order.
     */
    public function commitment(JobOrder $jobOrder): static
    {
        return $this->state(fn (array $attributes): array => [
            'job_order_id' => $jobOrder->id,
            'quantity_change' => -fake()->numberBetween(1, 5),
            'inventory_movement_type_id' => InventoryMovementType::query()
                ->firstOrCreate(['name' => 'order_commitment'])->id,
        ]);
    }

    public function reversal(JobOrder $jobOrder): static
    {
        return $this->state(fn (array $attributes): array => [
            'job_order_id' => $jobOrder->id,
            'quantity_change' => fake()->numberBetween(1, 5),
            'inventory_movement_type_id' => InventoryMovementType::query()
                ->firstOrCreate(['name' => 'order_reversal'])->id,
        ]);
    }
}
