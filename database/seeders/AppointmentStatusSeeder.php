<?php

namespace Database\Seeders;

use App\Models\AppointmentStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppointmentStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $approvedStatuses = collect([
            'pending',
            'confirmed',
            'arrived',
            'completed',
            'no_show',
            'cancelled',
        ]);

        $approvedStatuses->each(fn (string $name) => AppointmentStatus::query()->firstOrCreate([
            'name' => $name,
        ]));

        $pendingStatusId = AppointmentStatus::query()->where('name', 'pending')->value('id');
        $rescheduledStatusId = AppointmentStatus::query()->where('name', 'rescheduled')->value('id');

        if ($rescheduledStatusId !== null) {
            DB::table('appointments')
                ->where('appointment_status_id', $rescheduledStatusId)
                ->update(['appointment_status_id' => $pendingStatusId]);

            AppointmentStatus::query()->whereKey($rescheduledStatusId)->delete();
        }
    }
}
