<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize existing names: trim and collapse whitespace.
        DB::table('product_variants')->whereNotNull('name')->eachById(function (object $row): void {
            $normalized = trim(preg_replace('/\s+/', ' ', $row->name));
            if ($normalized !== $row->name) {
                DB::table('product_variants')->where('id', $row->id)->update(['name' => $normalized]);
            }
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['product_id', 'name'], 'product_variants_product_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique('product_variants_product_id_name_unique');
        });
    }
};
