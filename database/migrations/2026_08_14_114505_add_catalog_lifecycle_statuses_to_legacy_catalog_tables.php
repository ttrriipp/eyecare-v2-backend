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
        foreach (['brands', 'product_categories', 'lens_categories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->index()->after('id');
            });
        }

        foreach ([
            'brands',
            'product_categories',
            'lens_categories',
            'products',
            'product_variants',
        ] as $tableName) {
            DB::table($tableName)
                ->whereNotNull('deleted_at')
                ->update(['is_active' => false]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['brands', 'product_categories', 'lens_categories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['is_active']);
                $table->dropColumn('is_active');
            });
        }
    }
};
