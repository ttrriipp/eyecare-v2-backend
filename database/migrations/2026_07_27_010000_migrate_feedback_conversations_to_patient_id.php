<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $table): void {
            $table->foreignId('patient_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE feedback SET patient_id = (SELECT patients.id FROM patients WHERE patients.user_id = feedback.customer_id)');

        Schema::table('feedback', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });

    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('feedback', function (Blueprint $table): void {
            $table->dropForeign(['patient_id']);
            $table->dropColumn('patient_id');
        });

    }
};
