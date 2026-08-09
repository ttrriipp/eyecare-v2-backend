<?php

use App\Enums\CommercialItemKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('item_kind', 30)->nullable()->after('item_type');
            $table->json('item_snapshot')->nullable()->after('item_kind');
        });

        Schema::table('job_order_items', function (Blueprint $table) {
            $table->string('item_kind', 30)->nullable()->after('item_type');
            $table->json('item_snapshot')->nullable()->after('item_kind');
        });

        $this->backfillItemKinds();

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('item_kind', 30)->default(CommercialItemKind::CustomProduct->value)->nullable(false)->change();
        });

        Schema::table('job_order_items', function (Blueprint $table) {
            $table->string('item_kind', 30)->default(CommercialItemKind::CustomProduct->value)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn(['item_kind', 'item_snapshot']);
        });

        Schema::table('job_order_items', function (Blueprint $table) {
            $table->dropColumn(['item_kind', 'item_snapshot']);
        });
    }

    private function backfillItemKinds(): void
    {
        foreach (['quotation_items', 'job_order_items'] as $table) {
            // Service items (quotation_items has service_id)
            if ($table === 'quotation_items') {
                DB::table($table)
                    ->whereNotNull('service_id')
                    ->update(['item_kind' => CommercialItemKind::Service->value]);
            }

            // Lens package items
            DB::table($table)
                ->whereNotNull('lens_category_id')
                ->whereNull('item_kind')
                ->update(['item_kind' => CommercialItemKind::LensPackage->value]);

            // Product-backed items: derive from product.product_type
            DB::table($table)
                ->whereNotNull('product_variant_id')
                ->whereNull('item_kind')
                ->update(['item_kind' => DB::raw("
                    COALESCE(
                        (SELECT CASE
                            WHEN p.product_type = 'frame' THEN '".CommercialItemKind::Frame->value."'
                            WHEN p.product_type = 'contact_lens' THEN '".CommercialItemKind::ContactLens->value."'
                            WHEN p.product_type = 'accessory' THEN '".CommercialItemKind::Accessory->value."'
                            ELSE '".CommercialItemKind::CustomProduct->value."'
                        END
                        FROM product_variants pv
                        JOIN products p ON p.id = pv.product_id
                        WHERE pv.id = {$table}.product_variant_id),
                        '".CommercialItemKind::CustomProduct->value."'
                    )
                ")]);

            // Everything else: custom_product
            DB::table($table)
                ->whereNull('item_kind')
                ->update(['item_kind' => CommercialItemKind::CustomProduct->value]);
        }
    }
};
