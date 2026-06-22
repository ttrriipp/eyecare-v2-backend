<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->integer('previous_stock')->nullable()->after('quantity_change');
            $table->integer('new_stock')->nullable()->after('previous_stock');
            $table->foreignId('created_by')->nullable()->after('new_stock')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['previous_stock', 'new_stock', 'created_by']);
        });
    }
};
