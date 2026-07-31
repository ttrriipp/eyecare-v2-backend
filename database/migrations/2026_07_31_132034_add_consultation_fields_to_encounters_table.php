<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table) {
            $table->text('chief_complaint')->nullable()->after('remarks');
            $table->text('past_ocular_history')->nullable()->after('chief_complaint');
            $table->text('past_surgical_history')->nullable()->after('past_ocular_history');
            $table->text('past_medical_history')->nullable()->after('past_surgical_history');
            $table->text('allergies')->nullable()->after('past_medical_history');
            $table->text('medications')->nullable()->after('allergies');
            $table->text('plan')->nullable()->after('medications');
            $table->unsignedSmallInteger('last_wizard_step')->nullable()->after('plan');
            $table->timestamp('draft_saved_at')->nullable()->after('last_wizard_step');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete()->after('draft_saved_at');
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn([
                'chief_complaint',
                'past_ocular_history',
                'past_surgical_history',
                'past_medical_history',
                'allergies',
                'medications',
                'plan',
                'last_wizard_step',
                'draft_saved_at',
            ]);
        });
    }
};
