<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->string('purchase_order_number')->nullable()->after('purchased_at');
            $table->string('delivery_receipt_number')->nullable()->after('purchase_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn(['purchase_order_number', 'delivery_receipt_number']);
        });
    }
};
