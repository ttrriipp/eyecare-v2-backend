<?php

namespace Database\Seeders;

use App\Models\AppointmentStatus;
use Illuminate\Database\Seeder;

class AppointmentStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            'pending',
            'confirmed',
            'rescheduled',
            'cancelled',
            'completed',
        ])->each(fn (string $name) => AppointmentStatus::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
