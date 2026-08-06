<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->json('prescription_draft')->nullable()->after('draft_saved_at');
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropColumn('prescription_draft');
        });
    }
};
