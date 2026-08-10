<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->foreignId('lens_option_id')
                ->nullable()
                ->after('lens_category_id')
                ->constrained('lens_options')
                ->nullOnDelete();
        });

        Schema::table('job_order_items', function (Blueprint $table): void {
            $table->foreignId('lens_option_id')
                ->nullable()
                ->after('lens_category_id')
                ->constrained('lens_options')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_order_items', function (Blueprint $table): void {
            $table->dropForeign(['lens_option_id']);
            $table->dropColumn('lens_option_id');
        });

        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropForeign(['lens_option_id']);
            $table->dropColumn('lens_option_id');
        });
    }
};
