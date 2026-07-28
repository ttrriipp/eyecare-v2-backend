<?php

namespace Database\Factories;

use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingPayment>
 */
class BillingPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_record_id' => BillingRecord::factory(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'payment_method' => fake()->randomElement(['cash', 'gcash', 'bank_transfer', 'card']),
            'reference_number' => null,
            'status' => 'posted',
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
            'notes' => null,
            'reversed_by' => null,
            'reversed_at' => null,
            'reversal_reason' => null,
        ];
    }

    public function reversed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'reversed',
            'reversed_by' => User::factory(),
            'reversed_at' => now(),
            'reversal_reason' => 'Test reversal',
        ]);
    }
}
