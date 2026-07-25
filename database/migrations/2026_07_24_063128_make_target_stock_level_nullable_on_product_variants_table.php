<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('target_stock_level')
                ->nullable()
                ->default(null)
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('product_variants')
            ->whereNull('target_stock_level')
            ->update(['target_stock_level' => 0]);

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('target_stock_level')
                ->nullable(false)
                ->default(0)
                ->change();
        });
    }
};
