<?php

namespace Database\Seeders;

use App\Models\ProviderHour;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProviderHoursSeeder extends Seeder
{
    public function run(): void
    {
        $optometrists = User::query()->optometrists()->get();

        foreach ($optometrists as $optometrist) {
            for ($weekday = 0; $weekday <= 6; $weekday++) {
                ProviderHour::query()->firstOrCreate(
                    ['user_id' => $optometrist->id, 'weekday' => $weekday],
                    [
                        'start_time' => '09:00',
                        'end_time' => '17:00',
                        'enabled' => true,
                    ],
                );
            }
        }
    }
}
