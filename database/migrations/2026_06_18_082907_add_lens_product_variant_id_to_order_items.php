<?php

use App\Models\ProductVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('lens_product_variant_id')
                ->nullable()
                ->after('lens_type_id')
                ->constrained('product_variants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeignIdFor(ProductVariant::class, 'lens_product_variant_id');
            $table->dropColumn('lens_product_variant_id');
        });
    }
};
