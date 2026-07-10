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
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('optometrist_id')->nullable()->after('staff_id')->constrained('users')->nullOnDelete();
            $table->string('source', 32)->default('staff_created')->after('optometrist_id')->index();
            $table->timestamp('checked_in_at')->nullable()->after('scheduled_at');
            $table->timestamp('completed_at')->nullable()->after('checked_in_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_optometrist')->default(false)->after('role_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['optometrist_id']);
            $table->dropIndex(['source']);
            $table->dropColumn(['optometrist_id', 'source', 'checked_in_at', 'completed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_optometrist']);
            $table->dropColumn('is_optometrist');
        });
    }
};
