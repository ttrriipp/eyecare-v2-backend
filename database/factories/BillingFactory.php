<?php

namespace Database\Factories;

use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Billing>
 */
class BillingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->randomFloat(2, 50, 1000);

        return [
            'order_id' => Order::factory(),
            'billing_status_id' => BillingStatus::factory(),
            'total_amount' => $total,
            'amount_paid' => 0,
            'balance_due' => $total,
            'notes' => null,
            'issued_at' => null,
        ];
    }

    /**
     * Set the billing status to issued.
     */
    public function issued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'billing_status_id' => BillingStatus::query()->firstOrCreate(['name' => 'issued'])->id,
        ]);
    }

    /**
     * Set the billing status to issued.
     */
    public function issued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'billing_status_id' => BillingStatus::query()->firstOrCreate(['name' => 'issued'])->id,
            'issued_at' => now(),
        ]);
    }
}
