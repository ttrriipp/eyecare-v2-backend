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
            AppointmentTypeSeeder::class,
            AppointmentStatusSeeder::class,
            NotificationStatusSeeder::class,
            InventoryMovementTypeSeeder::class,
            CatalogSeeder::class,
            ClinicHoursSeeder::class,
            DemoUserSeeder::class,
            ProviderHoursSeeder::class,
            ClinicWorkflowSeeder::class,
        ]);
    }
}
