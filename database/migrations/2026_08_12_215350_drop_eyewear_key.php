<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('eyewear_key', 36)->nullable()->after('internal_notes');
            $table->unique('eyewear_key');
        });

        Schema::table('job_orders', function (Blueprint $table): void {
            $table->string('eyewear_key', 36)->nullable()->after('notes');
            $table->unique('eyewear_key');
        });
    }
};
