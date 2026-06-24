<?php

namespace Database\Factories;

use App\Models\Billing;
use App\Models\BillingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingItem>
 */
class BillingItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 100, 2000);

        return [
            'billing_id' => Billing::factory(),
            'type' => 'service',
            'description' => fake()->words(3, true),
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'amount' => $unitPrice,
            'order_item_id' => null,
            'service_record_id' => null,
        ];
    }

    public function product(): static
    {
        return $this->state(fn () => ['type' => 'product']);
    }

    public function service(): static
    {
        return $this->state(fn () => ['type' => 'service']);
    }
}
