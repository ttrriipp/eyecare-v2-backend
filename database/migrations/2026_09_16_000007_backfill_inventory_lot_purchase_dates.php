<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE inventory_lots
            INNER JOIN (
                SELECT inventory_lot_id, MIN(purchased_at) AS purchased_at
                FROM inventory_movements
                WHERE inventory_lot_id IS NOT NULL
                    AND purchased_at IS NOT NULL
                GROUP BY inventory_lot_id
            ) AS lot_purchases ON lot_purchases.inventory_lot_id = inventory_lots.id
            SET inventory_lots.purchased_at = lot_purchases.purchased_at
            WHERE inventory_lots.purchased_at IS NULL
        SQL);
    }

    public function down(): void
    {
        // Existing purchase dates must not be discarded during rollback.
    }
};
