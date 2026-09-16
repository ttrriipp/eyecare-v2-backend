<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->date('expires_on')->nullable()->change();
            $table->index(
                ['product_variant_id', 'purchased_at', 'received_at'],
                'inventory_lots_variant_batch_date_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropIndex('inventory_lots_variant_batch_date_index');
            $table->date('expires_on')->nullable(false)->change();
        });
    }
};
