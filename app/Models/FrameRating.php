<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'patient_id',
    'product_variant_id',
    'dispensing_event_id',
    'rating',
    'comment',
    'public_display_consent_at',
    'attachment_path',
    'attachment_public_id',
    'attachment_mime_type',
    'public_attachment_consent_at',
    'is_hidden',
    'moderation_reason',
    'moderated_by',
    'moderated_at',
])]
class FrameRating extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Scope to comments explicitly opted into public display.
     *
     * @param  Builder<FrameRating>  $query
     * @return Builder<FrameRating>
     */
    public function scopePubliclyDisplayable(Builder $query): Builder
    {
        $model = $query->getModel();
        $commentColumn = $model->qualifyColumn('comment');

        return $query
            ->whereNotNull($model->qualifyColumn('public_display_consent_at'))
            ->where($model->qualifyColumn('is_hidden'), false)
            ->whereNotNull($commentColumn)
            ->whereRaw("TRIM({$commentColumn}) <> ?", ['']);
    }

    /**
     * Scope to attachments independently opted into public display.
     *
     * @param  Builder<FrameRating>  $query
     * @return Builder<FrameRating>
     */
    public function scopePubliclyShareableAttachment(Builder $query): Builder
    {
        $model = $query->getModel();

        return $query
            ->publiclyDisplayable()
            ->whereNotNull($model->qualifyColumn('attachment_path'))
            ->whereNotNull($model->qualifyColumn('attachment_public_id'))
            ->whereIn($model->qualifyColumn('attachment_mime_type'), ['image/jpeg', 'image/png'])
            ->whereNotNull($model->qualifyColumn('public_attachment_consent_at'));
    }

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_hidden' => 'boolean',
            'moderated_at' => 'datetime',
            'public_display_consent_at' => 'datetime',
            'public_attachment_consent_at' => 'datetime',
        ];
    }
}
