<?php

namespace Database\Seeders;

use App\Models\InventoryMovementStatus;
use Illuminate\Database\Seeder;

class InventoryMovementStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            'initial',
            'manual_adjustment',
            'order_commitment',
            'order_reversal',
        ])->each(fn (string $name) => InventoryMovementStatus::query()->firstOrCreate([
            'name' => $name,
        ]));
    }
}
