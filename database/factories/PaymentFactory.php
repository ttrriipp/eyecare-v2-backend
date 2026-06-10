<?php

namespace Database\Factories;

use App\Models\Billing;
use App\Models\Payment;
use App\Models\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_id' => Billing::factory(),
            'payment_status_id' => PaymentStatus::factory(),
            'amount' => fake()->randomFloat(2, 10, 500),
            'method' => fake()->randomElement(['cash', 'gcash', 'bank_transfer']),
            'reference_number' => fake()->optional()->numerify('REF-######'),
            'notes' => null,
            'paid_at' => now(),
        ];
    }

    /**
     * Create a posted (active) payment.
     */
    public function posted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_status_id' => PaymentStatus::query()->firstOrCreate(['name' => 'posted'])->id,
        ]);
    }

    /**
     * Create a voided payment.
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_status_id' => PaymentStatus::query()->firstOrCreate(['name' => 'voided'])->id,
        ]);
    }
}
