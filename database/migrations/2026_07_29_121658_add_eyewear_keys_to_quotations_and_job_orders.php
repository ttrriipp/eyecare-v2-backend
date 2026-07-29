<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('eyewear_key', 36)->nullable()->after('internal_notes');
        });

        Schema::table('job_orders', function (Blueprint $table): void {
            $table->string('eyewear_key', 36)->nullable()->after('notes');
        });

        // Backfill quotations with eyw_{ULID} keys
        $quotations = DB::table('quotations')
            ->whereNull('eyewear_key')
            ->pluck('id');

        foreach ($quotations as $quotationId) {
            DB::table('quotations')
                ->where('id', $quotationId)
                ->update(['eyewear_key' => 'eyw_'.Str::ulid()]);
        }

        // Copy linked keys from quotations to job_orders via quotation_revisions
        DB::statement('
            UPDATE job_orders jo
            INNER JOIN quotation_revisions qr ON qr.id = jo.quotation_revision_id
            INNER JOIN quotations q ON q.id = qr.quotation_id
            SET jo.eyewear_key = q.eyewear_key
            WHERE jo.eyewear_key IS NULL
        ');

        // Generate keys for job-order-only records (no linked quotation)
        $orphanJobOrders = DB::table('job_orders')
            ->whereNull('eyewear_key')
            ->pluck('id');

        foreach ($orphanJobOrders as $jobOrderId) {
            DB::table('job_orders')
                ->where('id', $jobOrderId)
                ->update(['eyewear_key' => 'eyw_'.Str::ulid()]);
        }

        // Make both columns required and unique
        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('eyewear_key', 36)->nullable(false)->change();
            $table->unique('eyewear_key');
        });

        Schema::table('job_orders', function (Blueprint $table): void {
            $table->string('eyewear_key', 36)->nullable(false)->change();
            $table->unique('eyewear_key');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropUnique(['eyewear_key']);
            $table->dropColumn('eyewear_key');
        });

        Schema::table('job_orders', function (Blueprint $table): void {
            $table->dropUnique(['eyewear_key']);
            $table->dropColumn('eyewear_key');
        });
    }
};
