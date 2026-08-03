<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add item_type to quotation_items
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->string('item_type', 20)->nullable()->after('lens_category_id');
        });

        // 2. Backfill quotation_items: product if has variant or lens, else legacy_other
        DB::statement(<<<'SQL'
            UPDATE quotation_items
            SET item_type = CASE
                WHEN product_variant_id IS NOT NULL OR lens_category_id IS NOT NULL THEN 'product'
                ELSE 'legacy_other'
            END
            WHERE item_type IS NULL
        SQL);

        // 3. Make item_type required
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->string('item_type', 20)->default('product')->change();
        });

        // 4. Add item_type to job_order_items
        Schema::table('job_order_items', function (Blueprint $table): void {
            $table->string('item_type', 20)->nullable()->after('lens_category_id');
        });

        // 5. Backfill job_order_items from matching quotation_items or infer
        DB::statement(<<<'SQL'
            UPDATE job_order_items joi
            LEFT JOIN quotation_items qi ON joi.description = qi.description
                AND joi.unit_price = qi.unit_price
                AND qi.item_type IS NOT NULL
            SET joi.item_type = COALESCE(qi.item_type, 'legacy_other')
            WHERE joi.item_type IS NULL
        SQL);

        // 6. Make item_type required on job_order_items
        Schema::table('job_order_items', function (Blueprint $table): void {
            $table->string('item_type', 20)->default('product')->change();
        });

        // 7. Add fulfillment metadata to job_orders
        Schema::table('job_orders', function (Blueprint $table): void {
            $table->string('fulfillment_mode', 20)->default('prepared')->after('status');
            $table->boolean('uses_external_supplier')->default(false)->after('fulfillment_mode');
        });

        // 8. Backfill existing job_orders as prepared work
        DB::statement(<<<'SQL'
            UPDATE job_orders
            SET fulfillment_mode = 'prepared'
            WHERE fulfillment_mode IS NULL OR fulfillment_mode = ''
        SQL);
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table): void {
            $table->dropColumn(['fulfillment_mode', 'uses_external_supplier']);
        });

        Schema::table('job_order_items', function (Blueprint $table): void {
            $table->dropColumn('item_type');
        });

        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropColumn('item_type');
        });
    }
};
