<?php

namespace Database\Factories;

use App\Models\AppointmentType;
use App\Models\AppointmentTypeVisitReasonPreset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentTypeVisitReasonPreset>
 */
class AppointmentTypeVisitReasonPresetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'appointment_type_id' => AppointmentType::factory(),
            'label' => fake()->sentence(3),
            'sort_order' => fake()->numberBetween(0, 10),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
