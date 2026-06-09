<?php

namespace Database\Seeders;

use App\Models\OrderStatus;
use Illuminate\Database\Seeder;

class OrderStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            'requested',
            'under_review',
            'confirmed',
            'preparing',
            'ready_for_pickup',
            'completed',
            'cancelled',
        ])->each(fn (string $name) => OrderStatus::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
