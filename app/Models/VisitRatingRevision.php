<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'visit_rating_id',
    'revision_number',
    'rating',
    'comment',
    'revised_by',
    'revised_at',
])]
class VisitRatingRevision extends Model
{
    /**
     * @return BelongsTo<VisitRating, $this>
     */
    public function visitRating(): BelongsTo
    {
        return $this->belongsTo(VisitRating::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'revision_number' => 'integer',
            'revised_at' => 'datetime',
        ];
    }
}
