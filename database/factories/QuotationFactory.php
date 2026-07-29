<?php

namespace Database\Factories;

use App\Enums\QuotationStatus;
use App\Models\Patient;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_number' => 'QUO-'.Str::ulid(),
            'patient_id' => Patient::factory(),
            'encounter_id' => null,
            'prescription_id' => null,
            'status' => QuotationStatus::Draft,
            'valid_until' => fake()->dateTimeBetween('+7 days', '+30 days'),
            'notes' => fake()->optional()->sentence(),
            'internal_notes' => null,
            'eyewear_key' => 'eyw_'.Str::ulid(),
        ];
    }
}
