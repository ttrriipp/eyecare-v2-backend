<?php

namespace Database\Seeders;

use App\Models\VisitReason;
use Illuminate\Database\Seeder;

class VisitReasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            'eye_exam',
            'follow_up',
            'prescription_check',
        ])->each(fn (string $name) => VisitReason::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
