<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('contact_email_lookup_hash')->nullable()->index()->after('contact_email');
            $table->string('phone_lookup_hash')->nullable()->index()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['contact_email_lookup_hash', 'phone_lookup_hash']);
        });
    }
};
