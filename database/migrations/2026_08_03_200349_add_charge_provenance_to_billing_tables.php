<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add quotation_id to billing_records
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->foreignId('quotation_id')->nullable()->after('encounter_id')->constrained('quotations')->nullOnDelete();
        });

        // Add quotation_item_id and source_kind to billing_record_items
        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->foreignId('quotation_item_id')->nullable()->after('job_order_item_id')->constrained('quotation_items')->nullOnDelete();
            $table->string('source_kind', 20)->default('optical_order')->after('encounter_id');
        });

        // Add unique constraint for idempotent quoted service snapshotting
        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->unique(['billing_record_id', 'quotation_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_record_items', function (Blueprint $table): void {
            $table->dropUnique(['billing_record_id', 'quotation_item_id']);
            $table->dropColumn(['quotation_item_id', 'source_kind']);
        });

        Schema::table('billing_records', function (Blueprint $table): void {
            $table->dropForeign(['quotation_id']);
            $table->dropColumn('quotation_id');
        });
    }
};
