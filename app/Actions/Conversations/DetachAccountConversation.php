<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DetachAccountConversation
{
    /**
     * Detach mobile ownership from the account's Patient-associated conversation.
     *
     * Called inside the same transaction that clears patients.user_id.
     * Retains patient_id, messages, attachments, and legacy context rows
     * as clinic history. The detached conversation is no longer accessible
     * to the mobile account.
     */
    public function handle(User $account): void
    {
        DB::transaction(function () use ($account): void {
            $conversation = Conversation::query()
                ->where('account_user_id', $account->id)
                ->whereNotNull('patient_id')
                ->lockForUpdate()
                ->first();

            if ($conversation !== null) {
                $conversation->update(['account_user_id' => null]);
            }
        });
    }
}
