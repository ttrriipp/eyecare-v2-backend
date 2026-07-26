<?php

namespace Database\Factories;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'original_job_order_id' => null,
            'original_dispensing_event_id' => null,
            'status' => ComplaintStatus::Open,
            'patient_description' => fake()->sentence(),
            'resolution_notes' => null,
            'new_appointment_id' => null,
            'new_encounter_id' => null,
            'created_by' => null,
            'complaint_date' => now(),
        ];
    }
}
