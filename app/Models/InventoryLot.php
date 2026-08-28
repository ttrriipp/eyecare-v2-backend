<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\InventoryLotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

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

    public const EXPIRY_WARNING_DAYS = 90;

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
        return $this->hasMany(InventoryMovement::class, 'inventory_lot_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeNotExpired(Builder $query, ?CarbonInterface $asOf = null): void
    {
        $query->whereDate('expires_on', '>=', self::asOfDate($asOf)->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeExpired(Builder $query, ?CarbonInterface $asOf = null): void
    {
        $query->whereDate('expires_on', '<', self::asOfDate($asOf)->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('quantity_on_hand', '>', 0);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeExpiringSoon(
        Builder $query,
        ?int $days = null,
        ?CarbonInterface $asOf = null,
    ): void {
        $days ??= (int) config(
            'inventory.contact_lens_expiry_warning_days',
            self::EXPIRY_WARNING_DAYS,
        );

        if ($days < 0) {
            throw new InvalidArgumentException('Expiry warning days must be zero or greater.');
        }

        $start = self::asOfDate($asOf);
        $end = $start->addDays($days);

        $query->available()->whereBetween('expires_on', [
            $start->toDateString(),
            $end->toDateString(),
        ]);
    }

    public function isExpired(?CarbonInterface $asOf = null): bool
    {
        return $this->expires_on->toDateString() < self::asOfDate($asOf)->toDateString();
    }

    public function isAvailable(?CarbonInterface $asOf = null): bool
    {
        return $this->quantity_on_hand > 0 && ! $this->isExpired($asOf);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'received_at' => 'datetime',
            'received_quantity' => 'integer',
            'quantity_on_hand' => 'integer',
        ];
    }

    private static function asOfDate(?CarbonInterface $asOf): CarbonImmutable
    {
        return ($asOf === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($asOf)
        )->startOfDay();
    }
}
