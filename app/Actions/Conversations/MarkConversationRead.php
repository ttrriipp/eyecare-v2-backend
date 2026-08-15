<?php

namespace App\Actions\Conversations;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MarkConversationRead
{
    /**
     * Mark every message the caller did not send as read.
     *
     * Returns the number of rows marked. Idempotent — a second call
     * returns 0 because there are no remaining null read_at rows.
     */
    public function handle(User $account): int
    {
        $conversation = app(ResolveAccountConversation::class)->handle($account);

        return DB::transaction(fn (): int => $conversation->messages()
            ->where('sender_id', '!=', $account->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]));
    }
}
