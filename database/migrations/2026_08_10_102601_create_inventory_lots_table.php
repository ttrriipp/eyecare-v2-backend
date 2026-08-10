<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('lot_number', 50);
            $table->date('expires_on');
            $table->unsignedInteger('received_quantity');
            $table->unsignedInteger('quantity_on_hand');
            $table->timestamp('received_at');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->string('source_reference')->nullable();
            $table->timestamps();

            $table->unique(['product_variant_id', 'lot_number']);
            $table->index(['product_variant_id', 'expires_on']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('inventory_lot_id')->nullable()->after('job_order_id')->constrained('inventory_lots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['inventory_lot_id']);
            $table->dropColumn('inventory_lot_id');
        });

        Schema::dropIfExists('inventory_lots');
    }
};
