<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'frame_rating_id',
    'revision_number',
    'rating',
    'comment',
    'revised_by',
    'revised_at',
])]
class FrameRatingRevision extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<FrameRating, $this>
     */
    public function rating(): BelongsTo
    {
        return $this->belongsTo(FrameRating::class, 'frame_rating_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'revised_at' => 'datetime',
        ];
    }
}
