<?php

namespace Database\Seeders;

use App\Models\ClinicHour;
use Illuminate\Database\Seeder;

class ClinicHoursSeeder extends Seeder
{
    public function run(): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            ClinicHour::query()->firstOrCreate(
                ['weekday' => $weekday],
                [
                    'open_time' => '09:00',
                    'close_time' => '17:00',
                    'enabled' => true,
                ],
            );
        }
    }
}
