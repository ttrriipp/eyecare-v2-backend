<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'quotation_id',
    'revision_number',
    'subtotal',
    'discount_amount',
    'total',
    'notes',
    'presented_by',
    'presented_at',
    'accepted_by',
    'accepted_at',
])]
class QuotationRevision extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function presentedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presented_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * Recalculate totals from items.
     */
    public function recalculateTotals(): void
    {
        $subtotal = $this->items()->sum('amount');
        $this->update([
            'subtotal' => $subtotal,
            'total' => max($subtotal - $this->discount_amount, 0),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'presented_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
