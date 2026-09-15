<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('default_variant_attributes')->nullable();
        });

        // Backfill: copy common attributes from existing variants.
        $products = DB::table('products')->whereNull('deleted_at')->get();

        foreach ($products as $product) {
            $variants = DB::table('product_variants')
                ->where('product_id', $product->id)
                ->whereNull('deleted_at')
                ->whereNotNull('attributes')
                ->get();

            if ($variants->isEmpty()) {
                continue;
            }

            if ($variants->count() === 1) {
                $attrs = json_decode($variants->first()->attributes, true);
                if (! empty($attrs)) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['default_variant_attributes' => json_encode($attrs)]);
                }

                continue;
            }

            // Multiple variants: take only key/value pairs present and equal in all.
            $arrays = $variants->map(fn ($v) => json_decode($v->attributes, true) ?? [])->all();
            $common = $arrays[0];

            foreach ($common as $key => $value) {
                foreach ($arrays as $other) {
                    if (! array_key_exists($key, $other) || $other[$key] !== $value) {
                        unset($common[$key]);

                        break;
                    }
                }
            }

            if (! empty($common)) {
                DB::table('products')
                    ->where('id', $product->id)
                    ->update(['default_variant_attributes' => json_encode($common)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('default_variant_attributes');
        });
    }
};
