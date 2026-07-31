<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_invitations', function (Blueprint $table) {
            $table->string('invitation_code', 8)->nullable()->unique()->after('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('patient_invitations', function (Blueprint $table) {
            $table->dropColumn('invitation_code');
        });
    }
};
