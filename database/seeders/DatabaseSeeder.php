<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            VisitReasonSeeder::class,
            AppointmentTypeSeeder::class,
            AppointmentStatusSeeder::class,
            NotificationStatusSeeder::class,
            PaymentStatusSeeder::class,
            PaymentMethodSeeder::class,
            InventoryMovementTypeSeeder::class,
            CatalogSeeder::class,
            ClinicHoursSeeder::class,
            ServiceSeeder::class,
            DemoUserSeeder::class,
            ClinicWorkflowSeeder::class,
        ]);
    }
}
