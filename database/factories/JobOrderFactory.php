<?php

namespace Database\Factories;

use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<JobOrder>
 */
class JobOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_order_number' => fn (): string => 'JO-'.now()->format('Y').'-'.str_pad(fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'patient_id' => Patient::factory(),
            'encounter_id' => null,
            'prescription_id' => null,
            'quotation_id' => null,
            'status' => JobOrderStatus::Queued,
            'fulfillment_mode' => 'prepared',
            'uses_external_supplier' => false,
            'total_amount' => fake()->randomFloat(2, 100, 10000),
            'notes' => null,
            'supplier_invoice_number' => null,
            'eyewear_key' => 'eyw_'.Str::ulid(),
        ];
    }
}
