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
            ],
            [
                'name' => 'Follow-up',
                'patient_label' => 'Follow-up requested by the optometrist',
                'patient_description' => null,
                'duration_minutes' => 15,
                'requires_referral' => false,
                'old_duration' => null,
            ],
            [
                'name' => 'Routine Check-up',
                'patient_label' => 'Regular eye examination',
                'patient_description' => null,
                'duration_minutes' => 30,
                'requires_referral' => false,
                'old_duration' => null,
            ],
            [
                'name' => 'Referral',
                'patient_label' => 'Referral',
                'patient_description' => null,
                'duration_minutes' => 45,
                'requires_referral' => true,
                'old_duration' => 30,
            ],
            [
                'name' => 'Problem/Urgent Visit',
                'patient_label' => 'New or worsening eye concern',
                'patient_description' => null,
                'duration_minutes' => 30,
                'requires_referral' => false,
                'old_duration' => null,
            ],
            [
                'name' => 'Contact Lens Consultation',
                'patient_label' => 'Contact lens consultation',
                'patient_description' => null,
                'duration_minutes' => 45,
                'requires_referral' => false,
                'old_duration' => null,
            ],
        ];

        foreach ($types as $type) {
            $existing = AppointmentType::query()->where('name', $type['name'])->first();

            if ($existing) {
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
                AppointmentType::query()->create([
                    'name' => $type['name'],
                    'patient_label' => $type['patient_label'],
                    'patient_description' => $type['patient_description'],
                    'duration_minutes' => $type['duration_minutes'],
                    'requires_referral' => $type['requires_referral'],
                    'is_active' => true,
                    'is_patient_visible' => true,
                ]);
            }
        }
    }
}
