<?php

namespace Database\Factories;

use App\Enums\FrameSource;
use App\Models\JobOrder;
use App\Models\JobOrderEyewearSpecification;
use App\Models\JobOrderItem;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobOrderEyewearSpecification>
 */
class JobOrderEyewearSpecificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_order_id' => JobOrder::factory(),
            'prescription_id' => Prescription::factory(),
            'frame_job_order_item_id' => null,
            'lens_package_job_order_item_id' => JobOrderItem::factory(),
            'frame_source' => FrameSource::Catalog,
            'lens_design_snapshot' => fake()->word(),
            'lens_material_snapshot' => fake()->optional()->word(),
            'refractive_index_snapshot' => fake()->optional()->word(),
            'lens_options_snapshot' => null,
            'distance_pd_mode' => 'binocular',
            'distance_pd_binocular' => null,
            'distance_pd_od' => null,
            'distance_pd_os' => null,
            'near_pd_binocular' => null,
            'near_pd_od' => null,
            'near_pd_os' => null,
            'fitting_height_od' => null,
            'fitting_height_os' => null,
            'segment_height_od' => null,
            'segment_height_os' => null,
            'lab_instructions' => null,
            'approved_by' => null,
            'approved_at' => null,
            'verified_by' => null,
            'verified_at' => null,
            'verification_notes' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'approved_by' => User::factory()->optometrist(),
            'approved_at' => fake()->dateTimeBetween('-7 days', '-1 day'),
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verified_by' => User::factory()->staff(),
            'verified_at' => fake()->dateTimeBetween('-3 days', '-1 day'),
        ]);
    }

    public function patientSupplied(): static
    {
        return $this->state(fn (array $attributes): array => [
            'frame_source' => FrameSource::PatientSupplied,
        ]);
    }
}
