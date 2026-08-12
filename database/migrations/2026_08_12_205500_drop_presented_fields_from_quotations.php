<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropForeign(['presented_by']);
            $table->dropColumn(['presented_by', 'presented_at']);
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->foreignId('presented_by')->nullable()->constrained('users')->nullOnDelete()->after('total');
            $table->timestamp('presented_at')->nullable()->after('presented_by');
        });
    }
};
