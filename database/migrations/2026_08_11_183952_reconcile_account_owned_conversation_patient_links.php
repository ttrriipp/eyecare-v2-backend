<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('conversations as conversations')
            ->join('patients as patients', 'patients.user_id', '=', 'conversations.account_user_id')
            ->whereNull('conversations.patient_id')
            ->select([
                'conversations.id',
                'patients.id as patient_id',
            ])
            ->orderBy('conversations.id')
            ->get()
            ->each(function (object $conversation): void {
                DB::table('conversations')
                    ->where('id', $conversation->id)
                    ->whereNull('patient_id')
                    ->update(['patient_id' => $conversation->patient_id]);
            });
    }

    /**
     * The backfill is intentionally irreversible; resetting these associations
     * would discard valid current-link state.
     */
    public function down(): void {}
};
