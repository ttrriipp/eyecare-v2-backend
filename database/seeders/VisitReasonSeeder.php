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
        $reasons = [
            ['name' => 'Eye Exam', 'duration_minutes' => 30],
            ['name' => 'Follow-up', 'duration_minutes' => 15],
            ['name' => 'Prescription Check', 'duration_minutes' => 20],
            ['name' => 'Contact Lens Fitting', 'duration_minutes' => 60],
        ];

        foreach ($reasons as $reason) {
            VisitReason::query()->updateOrCreate(
                ['name' => $reason['name']],
                ['duration_minutes' => $reason['duration_minutes']],
            );
        }
    }
}
