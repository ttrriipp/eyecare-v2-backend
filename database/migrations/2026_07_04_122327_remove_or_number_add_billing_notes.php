<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table): void {
            $table->dropUnique('billings_or_number_unique');
            $table->dropColumn('or_number');
            $table->text('notes')->nullable()->after('balance_due');
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table): void {
            $table->dropColumn('notes');
            $table->string('or_number', 50)->nullable()->after('billing_number');
            $table->unique('or_number', 'billings_or_number_unique');
        });
    }
};
