<?php

namespace Database\Factories;

use App\Enums\QuotationStatus;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quotation_number' => 'QUO-'.Str::ulid(),
            'patient_id' => Patient::factory(),
            'encounter_id' => null,
            'prescription_id' => null,
            'status' => QuotationStatus::Draft,
            'valid_until' => fake()->dateTimeBetween('+7 days', '+30 days'),
            'subtotal' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'confirmed_by' => null,
            'confirmed_at' => null,
            'notes' => fake()->optional()->sentence(),
            'internal_notes' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuotationStatus::Accepted,
            'confirmed_by' => User::factory()->staff(),
            'confirmed_at' => fake()->dateTimeBetween('-7 days', '-1 day'),
        ]);
    }

    public function withTotals(float $subtotal, float $discount = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total' => max($subtotal - $discount, 0),
        ]);
    }
}
