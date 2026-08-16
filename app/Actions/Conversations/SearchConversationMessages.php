<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Validation\ValidationException;

final class SearchConversationMessages
{
    /**
     * Search messages within the account's conversation.
     *
     * Returns matches newest-first, paginated with the same cursor shape
     * as the regular messages endpoint. The query is scoped to the
     * resolved, ownership-checked conversation before the FULLTEXT clause.
     *
     * @return CursorPaginator<int, Message>
     */
    public function handle(User $account, string $query, int $perPage = 50): CursorPaginator
    {
        $conversation = app(ResolveAccountConversation::class)->handle($account);

        $searchTerm = trim($query);

        if ($searchTerm === '') {
            throw ValidationException::withMessages([
                'q' => ['Search query must not be empty.'],
            ]);
        }

        return $conversation->messages()
            ->with('attachments')
            ->whereFullText('body', $searchTerm)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
