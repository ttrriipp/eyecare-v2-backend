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
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('created_by')
                ->nullable()
                ->after('customer_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('checked_in_by')
                ->nullable()
                ->after('checked_in_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::table('appointments')
            ->whereNotNull('staff_id')
            ->update(['created_by' => DB::raw('staff_id')]);

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['staff_id']);
            $table->dropColumn('staff_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('staff_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::table('appointments')
            ->whereNotNull('created_by')
            ->update(['staff_id' => DB::raw('created_by')]);

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['checked_in_by']);
            $table->dropColumn(['created_by', 'checked_in_by']);
        });
    }
};
