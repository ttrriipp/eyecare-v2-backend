<?php

namespace Database\Factories;

use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingRecord>
 */
class BillingRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $totalAmount = fake()->randomFloat(2, 1000, 50000);

        return [
            'patient_id' => Patient::factory(),
            'job_order_id' => JobOrder::factory(),
            'encounter_id' => null,
            'status' => BillingRecordStatus::Unpaid,
            'subtotal_amount' => $totalAmount,
            'discount_amount' => 0,
            'total_amount' => $totalAmount,
            'amount_paid' => 0,
            'balance_due' => $totalAmount,
            'payment_due_date' => null,
            'notes' => null,
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
            'voided_by' => null,
            'voided_at' => null,
            'void_reason' => null,
        ];
    }

    public function opticalOnly(): static
    {
        return $this->state(fn () => [
            'job_order_id' => JobOrder::factory(),
            'encounter_id' => null,
        ]);
    }

    public function encounterOnly(): static
    {
        return $this->state(fn () => [
            'job_order_id' => null,
            'encounter_id' => Encounter::factory(),
        ]);
    }

    public function combined(): static
    {
        return $this->state(fn () => [
            'job_order_id' => JobOrder::factory(),
            'encounter_id' => Encounter::factory(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BillingRecordStatus::Paid,
            'amount_paid' => $attributes['total_amount'],
            'balance_due' => 0,
        ]);
    }

    public function partiallyPaid(): static
    {
        return $this->state(function (array $attributes): array {
            $half = $attributes['total_amount'] / 2;

            return [
                'status' => BillingRecordStatus::PartiallyPaid,
                'amount_paid' => $half,
                'balance_due' => $attributes['total_amount'] - $half,
            ];
        });
    }

    public function voided(): static
    {
        return $this->state(fn (): array => [
            'status' => BillingRecordStatus::Voided,
            'voided_by' => User::factory(),
            'voided_at' => now(),
            'void_reason' => 'Test void',
        ]);
    }

    public function withDiscount(float $discount): static
    {
        return $this->state(function (array $attributes) use ($discount): array {
            $subtotal = $attributes['subtotal_amount'] ?? $attributes['total_amount'];

            return [
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => max($subtotal - $discount, 0),
            ];
        });
    }
}
