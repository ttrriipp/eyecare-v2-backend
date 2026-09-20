<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update billing_records status from 'voided' to 'cancelled'
        DB::table('billing_records')
            ->where('status', 'voided')
            ->update(['status' => 'cancelled']);

        // Rename columns in billing_records
        Schema::table('billing_records', function (Blueprint $table) {
            $table->renameColumn('voided_by', 'cancelled_by');
            $table->renameColumn('voided_at', 'cancelled_at');
            $table->renameColumn('void_reason', 'cancellation_reason');
        });

        // Update encounters status from 'voided' to 'cancelled'
        DB::table('encounters')
            ->where('status', 'voided')
            ->update(['status' => 'cancelled']);

        // Rename columns in encounters
        Schema::table('encounters', function (Blueprint $table) {
            $table->renameColumn('voided_by', 'cancelled_by');
            $table->renameColumn('voided_at', 'cancelled_at');
            $table->renameColumn('void_reason', 'cancellation_reason');
        });

        // Rename columns in prescriptions
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->renameColumn('voided_by', 'cancelled_by');
            $table->renameColumn('voided_at', 'cancelled_at');
            $table->renameColumn('void_reason', 'cancellation_reason');
        });

        // Update audit_logs action values
        DB::table('audit_logs')
            ->where('action', 'encounter.voided')
            ->update(['action' => 'encounter.cancelled']);

        DB::table('audit_logs')
            ->where('action', 'prescription.voided')
            ->update(['action' => 'prescription.cancelled']);

        DB::table('audit_logs')
            ->where('action', 'invoice.voided')
            ->update(['action' => 'invoice.cancelled']);

        DB::table('audit_logs')
            ->where('action', 'billing_record.voided')
            ->update(['action' => 'billing_record.cancelled']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert audit_logs action values
        DB::table('audit_logs')
            ->where('action', 'encounter.cancelled')
            ->update(['action' => 'encounter.voided']);

        DB::table('audit_logs')
            ->where('action', 'prescription.cancelled')
            ->update(['action' => 'prescription.voided']);

        DB::table('audit_logs')
            ->where('action', 'invoice.cancelled')
            ->update(['action' => 'invoice.voided']);

        DB::table('audit_logs')
            ->where('action', 'billing_record.cancelled')
            ->update(['action' => 'billing_record.voided']);

        // Rename columns in prescriptions
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->renameColumn('cancelled_by', 'voided_by');
            $table->renameColumn('cancelled_at', 'voided_at');
            $table->renameColumn('cancellation_reason', 'void_reason');
        });

        // Rename columns in encounters
        Schema::table('encounters', function (Blueprint $table) {
            $table->renameColumn('cancelled_by', 'voided_by');
            $table->renameColumn('cancelled_at', 'voided_at');
            $table->renameColumn('cancellation_reason', 'void_reason');
        });

        // Update encounters status from 'cancelled' to 'voided'
        DB::table('encounters')
            ->where('status', 'cancelled')
            ->update(['status' => 'voided']);

        // Rename columns in billing_records
        Schema::table('billing_records', function (Blueprint $table) {
            $table->renameColumn('cancelled_by', 'voided_by');
            $table->renameColumn('cancelled_at', 'voided_at');
            $table->renameColumn('cancellation_reason', 'void_reason');
        });

        // Update billing_records status from 'cancelled' to 'voided'
        DB::table('billing_records')
            ->where('status', 'cancelled')
            ->update(['status' => 'voided']);
    }
};
