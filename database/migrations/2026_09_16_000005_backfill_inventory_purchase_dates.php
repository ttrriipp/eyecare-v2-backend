<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $restockTypeIds = DB::table('inventory_movement_types')
            ->where('name', 'restock')
            ->pluck('id');

        if ($restockTypeIds->isEmpty()) {
            return;
        }

        DB::table('inventory_movements')
            ->whereIn('inventory_movement_type_id', $restockTypeIds)
            ->where('quantity_change', '>', 0)
            ->whereNull('purchased_at')
            ->update([
                'purchased_at' => DB::raw('DATE(created_at)'),
            ]);
    }

    public function down(): void
    {
        // Existing purchase dates must not be discarded during rollback.
    }
};
