<?php

namespace App\Models;

use Database\Factories\InventoryLotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_variant_id',
    'lot_number',
    'expires_on',
    'received_quantity',
    'quantity_on_hand',
    'received_at',
    'received_by',
    'source_reference',
])]
class InventoryLot extends Model
{
    /** @use HasFactory<InventoryLotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'received_at' => 'datetime',
            'received_quantity' => 'integer',
            'quantity_on_hand' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Scope to non-expired lots.
     */
    public function scopeNotExpired(Builder $query): void
    {
        $query->where('expires_on', '>=', now()->toDateString());
    }

    /**
     * Scope to expired lots.
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('expires_on', '<', now()->toDateString());
    }

    /**
     * Scope to lots with available quantity.
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('quantity_on_hand', '>', 0);
    }

    public function isExpired(): bool
    {
        return $this->expires_on->isPast();
    }

    public function isAvailable(): bool
    {
        return ! $this->isExpired() && $this->quantity_on_hand > 0;
    }
}
