<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add nullable account_user_id if it doesn't exist
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'account_user_id')) {
                $table->foreignId('account_user_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('users')
                    ->restrictOnDelete();
            }
        });

        // Make patient_id nullable and remove unique constraint
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);
            $table->dropUnique(['patient_id']);
            $table->foreignId('patient_id')->nullable()->change();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->index('patient_id');
        });

        // Backfill: copy patients.user_id into account_user_id for existing linked conversations
        // This is deterministic and fails if the one-linked-account invariant is violated
        $conflicts = DB::table('conversations as c')
            ->join('patients as p', 'p.id', '=', 'c.patient_id')
            ->whereNotNull('p.user_id')
            ->select('c.id', 'c.patient_id', 'p.user_id')
            ->get();

        // Check for conflicts: one account owning multiple conversations
        $accountCounts = $conflicts->groupBy('user_id')->filter(fn ($group) => $group->count() > 1);
        if ($accountCounts->isNotEmpty()) {
            throw new RuntimeException(
                'Migration aborted: account(s) '.implode(', ', $accountCounts->keys()->toArray())
                .' own multiple conversations. Resolve manually before migrating.'
            );
        }

        foreach ($conflicts as $row) {
            DB::table('conversations')
                ->where('id', $row->id)
                ->update(['account_user_id' => $row->user_id]);
        }

        // Add unique constraint on account_user_id (only when not null)
        Schema::table('conversations', function (Blueprint $table) {
            $table->unique('account_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['account_user_id']);
            $table->dropForeign(['account_user_id']);
            $table->dropColumn('account_user_id');
            $table->unique('patient_id');
            $table->foreignId('patient_id')->nullable(false)->change();
        });
    }
};
