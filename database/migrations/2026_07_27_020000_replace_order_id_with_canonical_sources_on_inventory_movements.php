<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('reservation_id')->nullable()->after('product_variant_id');
            $table->foreignId('job_order_id')->nullable()->after('reservation_id');
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable()->after('product_variant_id');
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign(['reservation_id']);
            $table->dropForeign(['job_order_id']);
            $table->dropColumn(['reservation_id', 'job_order_id']);
        });
    }
};
