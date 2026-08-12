<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign(['inventory_lot_id']);
            $table->dropColumn('inventory_lot_id');
        });

        Schema::dropIfExists('inventory_lots');
    }

    public function down(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('lot_number', 50);
            $table->date('expires_on');
            $table->integer('received_quantity');
            $table->integer('quantity_on_hand');
            $table->timestamp('received_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_reference')->nullable();
            $table->timestamps();

            $table->unique(['product_variant_id', 'lot_number']);
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
        });
    }
};
