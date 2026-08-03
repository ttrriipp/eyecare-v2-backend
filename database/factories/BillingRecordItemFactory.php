<?php

namespace Database\Factories;

use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingRecordItem>
 */
class BillingRecordItemFactory extends Factory
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
            'billing_record_id' => BillingRecord::factory(),
            'item_type' => TransactionItemType::Service,
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $quantity * $unitPrice,
            'job_order_item_id' => null,
            'encounter_id' => null,
        ];
    }
}
