<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('lens_types', 'lens_categories');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign('products_lens_type_id_foreign');
            $table->renameColumn('lens_type_id', 'lens_category_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreign('lens_category_id')->references('id')->on('lens_categories')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign('order_items_lens_type_id_foreign');
            $table->renameColumn('lens_type_id', 'lens_category_id');
            $table->renameColumn('lens_type_name', 'lens_category_name');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreign('lens_category_id')->references('id')->on('lens_categories');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['lens_category_id']);
            $table->renameColumn('lens_category_id', 'lens_type_id');
            $table->renameColumn('lens_category_name', 'lens_type_name');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreign('lens_type_id')->references('id')->on('lens_categories');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['lens_category_id']);
            $table->renameColumn('lens_category_id', 'lens_type_id');
        });

        Schema::rename('lens_categories', 'lens_types');

        Schema::table('products', function (Blueprint $table): void {
            $table->foreign('lens_type_id')->references('id')->on('lens_types')->nullOnDelete();
        });
    }
};
