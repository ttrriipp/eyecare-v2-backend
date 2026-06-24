<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['name' => 'Comprehensive Eye Exam', 'description' => 'Full ocular health assessment including visual acuity, refraction, and eye pressure test.', 'price' => 800.00],
            ['name' => 'Contact Lens Fitting', 'description' => 'Professional fitting and assessment for contact lens prescription.', 'price' => 500.00],
            ['name' => 'Visual Field Test', 'description' => 'Peripheral vision assessment to detect glaucoma and other conditions.', 'price' => 300.00],
            ['name' => 'Frame Adjustment / Repair', 'description' => 'Minor frame adjustments, nose pad replacement, or basic repairs.', 'price' => 150.00],
            ['name' => 'Follow-up Consultation', 'description' => 'Post-treatment or post-procedure follow-up visit.', 'price' => 0.00],
        ];

        foreach ($services as $service) {
            Service::query()->firstOrCreate(['name' => $service['name']], $service);
        }
    }
}
