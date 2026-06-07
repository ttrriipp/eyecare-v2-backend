<?php

namespace Database\Seeders;

use App\Models\NotificationStatus;
use Illuminate\Database\Seeder;

class NotificationStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            'queued',
            'sent',
            'failed',
            'cancelled',
        ])->each(fn (string $name) => NotificationStatus::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
