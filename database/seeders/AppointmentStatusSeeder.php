<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatusName;
use App\Models\AppointmentStatus;
use Illuminate\Database\Seeder;

class AppointmentStatusSeeder extends Seeder
{
    /**
     * Seed the canonical statuses without pruning transitional lookup rows.
     *
     * Non-canonical rows remain temporarily so consumers can migrate
     * incrementally. Task 21 removes that transition bridge.
     */
    public function run(): void
    {
        $canonicalStatusNames = array_map(
            fn (AppointmentStatusName $status): string => $status->value,
            AppointmentStatusName::cases(),
        );

        foreach ([...$canonicalStatusNames, ...AppointmentStatusName::transitionBridgeValues()] as $statusName) {
            AppointmentStatus::query()->firstOrCreate([
                'name' => $statusName,
            ]);
        }
    }
}
