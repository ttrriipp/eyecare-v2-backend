<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->string('prescription_number', 32)->nullable()->after('id');
        });

        // Backfill existing prescriptions with sequential numbers
        DB::statement(<<<'SQL'
            UPDATE prescriptions p
            JOIN (
                SELECT id, CONCAT('RX-', DATE_FORMAT(created_at, '%Y'), '-', LPAD(ROW_NUMBER() OVER (ORDER BY created_at), 6, '0')) as rx_number
                FROM prescriptions
                WHERE deleted_at IS NULL
            ) p2 ON p.id = p2.id
            SET p.prescription_number = p2.rx_number
        SQL);

        // Make it unique and not null after backfill
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->string('prescription_number', 32)->nullable(false)->change();
            $table->unique('prescription_number');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropUnique(['prescription_number']);
            $table->dropColumn('prescription_number');
        });
    }
};
