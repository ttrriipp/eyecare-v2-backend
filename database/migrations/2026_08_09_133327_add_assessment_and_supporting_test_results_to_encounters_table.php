<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->text('assessment')->nullable()->after('plan');
            $table->text('supporting_test_results')->nullable()->after('assessment');
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropColumn(['assessment', 'supporting_test_results']);
        });
    }
};
