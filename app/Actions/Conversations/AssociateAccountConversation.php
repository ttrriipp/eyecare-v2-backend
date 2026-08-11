<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AssociateAccountConversation
{
    /**
     * Associate the account's current conversation with the verified Patient.
     *
     * Called inside the same transaction that activates patients.user_id.
     * Does not create a conversation if none exists. Does not claim or merge
     * older patient-only historical threads.
     */
    public function handle(User $account, Patient $patient): void
    {
        DB::transaction(function () use ($account, $patient): void {
            $conversation = Conversation::query()
                ->where('account_user_id', $account->id)
                ->lockForUpdate()
                ->first();

            if ($conversation !== null) {
                $conversation->update(['patient_id' => $patient->id]);
            }
        });
    }
}
