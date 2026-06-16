<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'message_id',
    'contextable_type',
    'contextable_id',
])]
class MessageContextLink extends Model
{
    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function contextable(): MorphTo
    {
        return $this->morphTo();
    }
}
