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
            AppointmentStatusSeeder::class,
            NotificationStatusSeeder::class,
            OrderStatusSeeder::class,
            BillingStatusSeeder::class,
            PaymentStatusSeeder::class,
            PaymentMethodSeeder::class,
            InventoryMovementStatusSeeder::class,
            InventoryMovementTypeSeeder::class,
            DiscountTypeSeeder::class,
            CatalogSeeder::class,
            DemoUserSeeder::class,
            ClinicWorkflowSeeder::class,
        ]);
    }
}
