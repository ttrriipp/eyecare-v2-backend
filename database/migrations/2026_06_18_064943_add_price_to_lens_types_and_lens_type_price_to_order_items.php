<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lens_types', function (Blueprint $table): void {
            $table->decimal('price', 10, 2)->nullable()->after('description');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('lens_type_price', 10, 2)->nullable()->after('lens_type_name');
        });
    }

    public function down(): void
    {
        Schema::table('lens_types', function (Blueprint $table): void {
            $table->dropColumn('price');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('lens_type_price');
        });
    }
};
