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
            'confirmed',
            'processing',
            'ready_for_pickup',
            'completed',
            'cancelled',
        ])->each(fn (string $name) => OrderStatus::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
