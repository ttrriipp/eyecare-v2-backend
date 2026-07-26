<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'patient_id',
    'product_variant_id',
    'dispensing_event_id',
    'rating',
    'comment',
    'current_revision_id',
])]
class FrameRating extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<DispensingEvent, $this>
     */
    public function dispensingEvent(): BelongsTo
    {
        return $this->belongsTo(DispensingEvent::class);
    }

    /**
     * @return HasMany<FrameRatingRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(FrameRatingRevision::class);
    }

    /**
     * @return BelongsTo<FrameRatingRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(FrameRatingRevision::class, 'current_revision_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }
}
