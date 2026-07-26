<?php

namespace Database\Seeders;

use App\Models\AppointmentType;
use Illuminate\Database\Seeder;

class AppointmentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'New Patient', 'duration_minutes' => 30, 'requires_referral' => false],
            ['name' => 'Follow-up', 'duration_minutes' => 15, 'requires_referral' => false],
            ['name' => 'Routine Check-up', 'duration_minutes' => 30, 'requires_referral' => false],
            ['name' => 'Referral', 'duration_minutes' => 30, 'requires_referral' => true],
        ];

        foreach ($types as $type) {
            AppointmentType::query()->firstOrCreate(
                ['name' => $type['name']],
                [
                    'duration_minutes' => $type['duration_minutes'],
                    'requires_referral' => $type['requires_referral'],
                    'is_active' => true,
                ],
            );
        }
    }
}
