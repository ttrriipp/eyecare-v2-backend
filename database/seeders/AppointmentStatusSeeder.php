<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatusName;
use App\Models\AppointmentStatus;
use Illuminate\Database\Seeder;

class AppointmentStatusSeeder extends Seeder
{
    /**
     * Seed the canonical appointment statuses.
     */
    public function run(): void
    {
        foreach (AppointmentStatusName::cases() as $status) {
            AppointmentStatus::query()->firstOrCreate([
                'name' => $status->value,
            ]);
        }
    }
}
