<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensing_events', function (Blueprint $table) {
            $table->decimal('released_balance_amount', 12, 2)->default(0)->after('notes');
            $table->foreignId('balance_override_by')->nullable()->constrained('users')->nullOnDelete()->after('released_balance_amount');
            $table->text('balance_override_reason')->nullable()->after('balance_override_by');
            $table->date('balance_due_date')->nullable()->after('balance_override_reason');
        });
    }

    public function down(): void
    {
        Schema::table('dispensing_events', function (Blueprint $table) {
            $table->dropForeign(['balance_override_by']);
            $table->dropColumn(['released_balance_amount', 'balance_override_by', 'balance_override_reason', 'balance_due_date']);
        });
    }
};
