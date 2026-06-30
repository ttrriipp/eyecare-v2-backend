<?php

namespace Database\Seeders;

use App\Models\InventoryMovementType;
use Illuminate\Database\Seeder;

class InventoryMovementTypeSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'restock',
            'manual_adjustment',
            'order_commitment',
            'order_reversal',
            'damaged',
        ])->each(fn (string $name) => InventoryMovementType::query()->firstOrCreate(['name' => $name]));
    }
}
