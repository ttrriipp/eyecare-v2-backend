<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
}
