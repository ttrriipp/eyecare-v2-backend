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
            'Eye Exam',
            'Follow-up',
            'Prescription Check',
        ])->each(fn (string $name) => VisitReason::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
