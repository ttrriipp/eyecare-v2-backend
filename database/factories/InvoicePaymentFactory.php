<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoicePayment>
 */
class InvoicePaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'payment_method' => fake()->randomElement(['cash', 'gcash', 'bank_transfer', 'credit_card']),
            'reference_number' => null,
            'recorded_by' => User::factory()->staff(),
            'recorded_at' => now(),
            'notes' => null,
        ];
    }
}
