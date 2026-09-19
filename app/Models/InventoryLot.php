<?php

namespace App\Models;

use App\Enums\ProductUsage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
    'purchased_at',
    'received_by',
    'source_reference',
])]
class InventoryLot extends Model
{
    /** @use HasFactory<InventoryLotFactory> */
    use HasFactory;

    public const int EXPIRY_WARNING_BUFFER_MONTHS = 2;

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
        $query->where(function (Builder $lotQuery) use ($asOf): void {
            $lotQuery
                ->whereNull('expires_on')
                ->orWhereDate('expires_on', '>=', self::asOfDate($asOf)->toDateString());
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeExpired(Builder $query, ?CarbonInterface $asOf = null): void
    {
        $query
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', self::asOfDate($asOf)->toDateString());
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
        ?CarbonInterface $asOf = null,
    ): void {
        $start = self::asOfDate($asOf);

        $query
            ->available()
            ->whereNotNull('expires_on')
            ->where(function (Builder $lotQuery) use ($start): void {
                foreach (ProductUsage::cases() as $usage) {
                    $end = $usage->addToDate($start)
                        ->addMonthsNoOverflow(self::expiryWarningBufferMonths());

                    $lotQuery->orWhere(function (Builder $usageLotQuery) use ($start, $end, $usage): void {
                        $usageLotQuery
                            ->whereBetween('expires_on', [
                                $start->toDateString(),
                                $end->toDateString(),
                            ])
                            ->whereHas(
                                'variant.product',
                                fn (Builder $productQuery): Builder => $productQuery->where('usage', $usage->value),
                            );
                    });
                }
            });
    }

    public function expiringSoonDate(): ?CarbonImmutable
    {
        $usage = $this->variant?->product?->usage;

        if (! $this->expires_on instanceof CarbonInterface || ! $usage instanceof ProductUsage) {
            return null;
        }

        return $usage->subtractFromDate($this->expires_on)
            ->subMonthsNoOverflow(self::expiryWarningBufferMonths());
    }

    public function isExpiringSoon(?CarbonInterface $asOf = null): bool
    {
        return $this->isAvailable($asOf)
            && ($this->expiringSoonDate()?->lessThanOrEqualTo(self::asOfDate($asOf)) ?? false);
    }

    public function isExpired(?CarbonInterface $asOf = null): bool
    {
        return $this->expires_on !== null
            && $this->expires_on->toDateString() < self::asOfDate($asOf)->toDateString();
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
            'purchased_at' => 'date',
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

    private static function expiryWarningBufferMonths(): int
    {
        return max(0, (int) config(
            'inventory.expiry_warning_buffer_months',
            self::EXPIRY_WARNING_BUFFER_MONTHS,
        ));
    }
}
