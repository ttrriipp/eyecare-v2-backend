<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table): void {
            $table->dropColumn(['notes', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table): void {
            $table->text('notes')->nullable();
            $table->date('due_date')->nullable();
        });
    }
};
