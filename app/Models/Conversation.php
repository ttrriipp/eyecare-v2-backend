<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'account_user_id',
    'patient_id',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'inbox_archived_at' => 'datetime',
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
}
