<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropColumn('item_type');
        });

        Schema::table('job_order_items', function (Blueprint $table): void {
            $table->dropColumn('item_type');
        });

        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->dropColumn('item_type');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->string('item_type', 20)->nullable()->after('item_snapshot');
        });

        Schema::table('job_order_items', function (Blueprint $table): void {
            $table->string('item_type', 20)->nullable()->after('item_snapshot');
        });

        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->string('item_type', 20)->nullable()->after('source_kind');
        });
    }
};
