<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delete remaining lens products (already deactivated by 2026_08_10_193536)
        DB::table('products')
            ->where('product_type', 'lens')
            ->delete();

        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['lens_category_id']);
            $table->dropColumn('lens_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('lens_category_id')->nullable()->constrained('lens_categories')->nullOnDelete();
        });
    }
};
