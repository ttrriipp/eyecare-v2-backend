<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'account_user_id',
    'patient_id',
    'staff_last_read_at',
    'inbox_archived_at',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'inbox_archived_at' => 'datetime',
            'staff_last_read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isUnlinked(): bool
    {
        return $this->account_user_id !== null && $this->patient_id === null;
    }

    public function isLinked(): bool
    {
        return $this->account_user_id !== null && $this->patient_id !== null;
    }

    public function isHistorical(): bool
    {
        return $this->account_user_id === null && $this->patient_id !== null;
    }

    /**
     * Archive the conversation from the staff inbox.
     */
    public function archiveInbox(): void
    {
        $this->update(['inbox_archived_at' => now()]);
    }

    /**
     * Restore the conversation to the staff inbox.
     */
    public function restoreToInbox(): void
    {
        $this->update(['inbox_archived_at' => null]);
    }

    /**
     * Check if the conversation is archived from the inbox.
     */
    public function isInboxArchived(): bool
    {
        return $this->inbox_archived_at !== null;
    }

    /**
     * Automatically restore to inbox when a new message arrives.
     */
    public function autoRestoreOnNewMessage(): void
    {
        if ($this->isInboxArchived()) {
            $this->restoreToInbox();
        }
    }

    /**
     * Count messages not sent by the account user that have not been read.
     *
     * Used by the patient mobile API. A null read_at means unread.
     */
    public function unreadForPatient(User $account): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $account->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Count patient messages newer than the staff read watermark.
     *
     * A null watermark means every patient message is unread.
     * Messages sent by staff are excluded — only patient messages
     * constitute "waiting" work for the inbox.
     */
    public function unreadForStaff(): int
    {
        $query = $this->messages()
            ->where('sender_id', $this->account_user_id ?? 0);

        if ($this->staff_last_read_at !== null) {
            $query->where('created_at', '>', $this->staff_last_read_at);
        }

        return $query->count();
    }

    /**
     * Eager-load staff-unread counts for a collection of conversations.
     *
     * Adds an `unread_for_staff` attribute to each conversation in one
     * query, avoiding N+1 in the inbox list.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeWithUnreadForStaff(Builder $query): Builder
    {
        return $query->withCount(['messages as unread_for_staff' => function ($q): void {
            $q->whereColumn('sender_id', '=', 'conversations.account_user_id')
                ->where(function ($q): void {
                    $q->whereNull('conversations.staff_last_read_at')
                        ->orWhereColumn('messages.created_at', '>', 'conversations.staff_last_read_at');
                });
        }]);
    }

    /**
     * Eager-load the latest message for each conversation.
     *
     * Adds `last_message_body` and `last_message_at` attributes.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeWithLastMessage(Builder $query): Builder
    {
        return $query->addSelect(['last_message_body' => Message::query()
            ->select('body')
            ->whereColumn('conversation_id', 'conversations.id')
            ->latest()
            ->limit(1),
        ])->addSelect(['last_message_at' => Message::query()
            ->select('created_at')
            ->whereColumn('conversation_id', 'conversations.id')
            ->latest()
            ->limit(1),
        ]);
    }
}
