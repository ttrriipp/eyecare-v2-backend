<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolveAccountConversation
{
    /**
     * Resolve the current account-owned conversation.
     *
     * Creates one lazily if it doesn't exist. Returns the existing
     * conversation or creates a new one with account_user_id set.
     */
    public function handle(User $account): Conversation
    {
        if (! $account->isPatient()) {
            throw ValidationException::withMessages([
                'account' => ['Only patient-role accounts can use conversations.'],
            ]);
        }

        return DB::transaction(fn (): Conversation => Conversation::query()
            ->firstOrCreate(
                ['account_user_id' => $account->id],
                ['patient_id' => $account->patient?->id],
            ));
    }
}
