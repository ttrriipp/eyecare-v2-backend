<?php

namespace Database\Factories;

use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
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
        return [
            'patient_id' => Patient::factory(),
            'job_order_id' => JobOrder::factory(),
            'encounter_id' => null,
            'status' => BillingRecordStatus::Unpaid,
            'total_amount' => fake()->randomFloat(2, 1000, 50000),
            'amount_paid' => 0,
            'balance_due' => fn (array $attributes) => $attributes['total_amount'],
            'notes' => null,
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
            'voided_by' => null,
            'voided_at' => null,
            'void_reason' => null,
        ];
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
}
