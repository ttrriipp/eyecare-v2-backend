<?php

namespace Database\Seeders;

use App\Models\AppointmentType;
use Illuminate\Database\Seeder;

class AppointmentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'New Patient',
                'patient_label' => 'First eye examination',
                'patient_description' => 'For your first examination at the clinic.',
                'duration_minutes' => 45,
                'requires_referral' => false,
                'old_duration' => 30,
                'visit_reason_presets' => [
                    'First eye examination',
                    'Blurred or reduced vision',
                    'Eye strain or headaches',
                ],
            ],
            [
                'name' => 'Follow-up',
                'patient_label' => 'Follow-up requested by the optometrist',
                'patient_description' => null,
                'duration_minutes' => 15,
                'requires_referral' => false,
                'old_duration' => null,
                'visit_reason_presets' => [
                    'Follow-up after a recent prescription change',
                    'Review test results',
                    'Monitor an ongoing eye condition',
                ],
            ],
            [
                'name' => 'Routine Check-up',
                'patient_label' => 'Regular eye examination',
                'patient_description' => null,
                'duration_minutes' => 30,
                'requires_referral' => false,
                'old_duration' => null,
                'visit_reason_presets' => [
                    'Routine eye examination',
                    'Prescription update',
                    'Eye health screening',
                ],
            ],
            [
                'name' => 'Referral',
                'patient_label' => 'Referral',
                'patient_description' => null,
                'duration_minutes' => 45,
                'requires_referral' => true,
                'old_duration' => 30,
                'visit_reason_presets' => [
                    'Referral from another provider',
                    'Second opinion',
                    'Specialist assessment',
                ],
            ],
            [
                'name' => 'Problem/Urgent Visit',
                'patient_label' => 'New or worsening eye concern',
                'patient_description' => null,
                'duration_minutes' => 30,
                'requires_referral' => false,
                'old_duration' => null,
                'visit_reason_presets' => [
                    'Blurred or reduced vision',
                    'Eye pain or discomfort',
                    'Redness or irritation',
                    'Sudden flashes or floaters',
                ],
            ],
            [
                'name' => 'Contact Lens Consultation',
                'patient_label' => 'Contact lens consultation',
                'patient_description' => null,
                'duration_minutes' => 45,
                'requires_referral' => false,
                'old_duration' => null,
                'visit_reason_presets' => [
                    'New contact lens fitting',
                    'Contact lens prescription update',
                    'Contact lens discomfort',
                ],
            ],
        ];

        foreach ($types as $type) {
            $existing = AppointmentType::query()->where('name', $type['name'])->first();

            if ($existing) {
                $appointmentType = $existing;
                $existing->update([
                    'patient_label' => $type['patient_label'],
                    'patient_description' => $type['patient_description'],
                    'is_patient_visible' => true,
                ]);

                if ($type['old_duration'] !== null
                    && $existing->duration_minutes === $type['old_duration']
                ) {
                    $existing->update(['duration_minutes' => $type['duration_minutes']]);
                }
            } else {
                $appointmentType = AppointmentType::query()->create([
                    'name' => $type['name'],
                    'patient_label' => $type['patient_label'],
                    'patient_description' => $type['patient_description'],
                    'duration_minutes' => $type['duration_minutes'],
                    'requires_referral' => $type['requires_referral'],
                    'is_active' => true,
                    'is_patient_visible' => true,
                ]);
            }

            $this->seedVisitReasonPresets($appointmentType, $type['visit_reason_presets']);
        }
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function seedVisitReasonPresets(AppointmentType $appointmentType, array $labels): void
    {
        foreach ($labels as $sortOrder => $label) {
            $appointmentType->visitReasonPresets()->updateOrCreate(
                ['label' => $label],
                [
                    'sort_order' => $sortOrder + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
