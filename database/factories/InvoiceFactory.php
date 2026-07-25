<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->randomFloat(2, 500, 20000);

        return [
            'invoice_number' => 'INV-'.now()->format('Y').'-'.str_pad(fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'official_number' => null,
            'patient_id' => Patient::factory(),
            'job_order_id' => null,
            'encounter_id' => null,
            'status' => InvoiceStatus::Issued,
            'sale_type' => 'cash_sale',
            'sold_to_name' => null,
            'subtotal' => $total,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => $total,
            'amount_paid' => 0,
            'balance_due' => $total,
            'notes' => null,
            'recorded_by' => null,
            'issued_at' => now(),
        ];
    }
}
