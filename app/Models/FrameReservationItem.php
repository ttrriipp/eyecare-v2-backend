<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'frame_reservation_id',
    'product_variant_id',
])]
class FrameReservationItem extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<FrameReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(FrameReservation::class, 'frame_reservation_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Scope reservation items that can be attached to a quotation for a patient.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEligibleForQuotation(Builder $query, int $patientId): void
    {
        $query
            ->whereHas('reservation', function (Builder $reservationQuery) use ($patientId): void {
                $reservationQuery
                    ->where('patient_id', $patientId)
                    ->whereIn('status', [
                        ReservationStatus::Requested,
                        ReservationStatus::Prepared,
                        ReservationStatus::TriedOn,
                    ])
                    ->whereDoesntHave('jobOrder');
            })
            ->whereHas('variant', function (Builder $variantQuery): void {
                $variantQuery
                    ->active()
                    ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery
                        ->where('is_active', true)
                        ->where('product_type', 'frame'));
            });
    }
}
