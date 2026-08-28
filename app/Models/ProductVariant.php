<?php

namespace App\Models;

use App\Enums\ArAssetStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[Fillable([
    'product_id',
    'name',
    'sku',
    'is_active',
    'price',
    'compare_at_price',
    'cost_price',
    'attributes',
    'stock_quantity',
    'low_stock_threshold',
    'target_stock_level',
    'ar_eligible',
    'ar_asset_reference',
    'published_ar_asset_id',
    'images',
])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $variant): void {
            if (empty($variant->sku)) {
                $variant->sku = self::generateSku();
            }
        });
    }

    private static function generateSku(): string
    {
        $sequence = self::query()->withTrashed()->count() + 1;

        return sprintf('VAR-%06d', $sequence);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->active());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeContactLenses(Builder $query): void
    {
        $query->whereHas(
            'product',
            fn (Builder $productQuery): Builder => $productQuery->where('product_type', 'contact_lens'),
        );
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeArReady(Builder $query): void
    {
        $query
            ->active()
            ->where('ar_eligible', true)
            ->whereNotNull('ar_asset_reference');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeNeedsReorder(Builder $query): void
    {
        $variantTable = $query->getModel()->getTable();
        $today = now()->toDateString();

        $query
            ->where('low_stock_threshold', '>', 0)
            ->where(function (Builder $stockQuery) use ($today, $variantTable): void {
                $stockQuery
                    ->where(function (Builder $aggregateQuery): void {
                        $aggregateQuery
                            ->whereDoesntHave(
                                'product',
                                fn (Builder $productQuery): Builder => $productQuery
                                    ->where('product_type', 'contact_lens'),
                            )
                            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
                    })
                    ->orWhere(function (Builder $contactLensQuery) use ($today, $variantTable): void {
                        $contactLensQuery
                            ->whereHas(
                                'product',
                                fn (Builder $productQuery): Builder => $productQuery
                                    ->where('product_type', 'contact_lens'),
                            )
                            ->whereRaw(
                                "(SELECT COALESCE(SUM(inventory_lots.quantity_on_hand), 0)
                                    FROM inventory_lots
                                    WHERE inventory_lots.product_variant_id = {$variantTable}.id
                                      AND inventory_lots.quantity_on_hand > 0
                                      AND inventory_lots.expires_on >= ?) <= {$variantTable}.low_stock_threshold",
                                [$today],
                            );
                    });
            });
    }

    /**
     * Instance-level counterpart to the needsReorder query scope, for row-level
     * display where the record is already loaded.
     */
    public function isLowStock(): bool
    {
        $stockQuantity = $this->isContactLens()
            ? $this->usableStockQuantity()
            : $this->stock_quantity;

        return $this->low_stock_threshold > 0
            && $stockQuantity !== null
            && $stockQuantity <= $this->low_stock_threshold;
    }

    public function replenishmentTarget(): ?int
    {
        return $this->target_stock_level;
    }

    public function suggestedReorderQuantity(): ?int
    {
        if ($this->target_stock_level === null) {
            return null;
        }

        $stockQuantity = $this->isContactLens()
            ? $this->usableStockQuantity()
            : $this->stock_quantity;

        return max($this->replenishmentTarget() - ($stockQuantity ?? 0), 0);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisibleInMobileCatalog(Builder $query): void
    {
        $query
            ->active()
            ->where(fn (Builder $variantQuery): Builder => $variantQuery
                ->whereHas(
                    'product',
                    fn (Builder $productQuery): Builder => $productQuery
                        ->active()
                        ->where('product_type', 'accessory'),
                )
                ->orWhere(fn (Builder $frameVariantQuery): Builder => $frameVariantQuery
                    ->where('ar_eligible', true)
                    ->whereNotNull('ar_asset_reference')
                    ->whereHas(
                        'product',
                        fn (Builder $productQuery): Builder => $productQuery
                            ->active()
                            ->where('product_type', 'frame'),
                    )));
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * @return HasMany<InventoryLot, $this>
     */
    public function inventoryLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function isContactLens(): bool
    {
        return $this->product?->product_type === 'contact_lens';
    }

    public function usableStockQuantity(?CarbonInterface $asOf = null): ?int
    {
        if (! $this->isContactLens()) {
            return null;
        }

        return (int) $this->inventoryLotsForDisplay()
            ->filter(fn (InventoryLot $lot): bool => $lot->isAvailable($asOf))
            ->sum('quantity_on_hand');
    }

    public function earliestUsableExpiry(?CarbonInterface $asOf = null): ?CarbonImmutable
    {
        if (! $this->isContactLens()) {
            return null;
        }

        $lot = $this->inventoryLotsForDisplay()
            ->filter(fn (InventoryLot $lot): bool => $lot->isAvailable($asOf))
            ->sortBy(fn (InventoryLot $lot): array => [
                $lot->expires_on->toDateString(),
                $lot->id,
            ])
            ->first();

        return $lot?->expires_on?->toImmutable();
    }

    public function expiryStatus(?CarbonInterface $asOf = null): ?string
    {
        if (! $this->isContactLens()) {
            return null;
        }

        $lots = $this->inventoryLotsForDisplay();
        $physicalQuantity = (int) $lots->sum('quantity_on_hand');

        if ($physicalQuantity === 0) {
            return 'out_of_stock';
        }

        $usableLots = $lots->filter(fn (InventoryLot $lot): bool => $lot->isAvailable($asOf));

        if ($usableLots->isEmpty()) {
            return 'expired';
        }

        $warningDays = max(0, (int) config(
            'inventory.contact_lens_expiry_warning_days',
            InventoryLot::EXPIRY_WARNING_DAYS,
        ));
        $warningEnd = self::asOfDate($asOf)->addDays($warningDays);

        return $usableLots->contains(
            fn (InventoryLot $lot): bool => $lot->expires_on->toDateString() <= $warningEnd->toDateString(),
        ) ? 'expiring_soon' : 'good';
    }

    public function expiryStatusLabel(?CarbonInterface $asOf = null): ?string
    {
        return match ($this->expiryStatus($asOf)) {
            'good' => 'Good',
            'expiring_soon' => 'Expiring Soon',
            'expired' => 'Expired',
            'out_of_stock' => 'Out of Stock',
            default => null,
        };
    }

    /**
     * @return HasMany<FrameRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(FrameRating::class, 'product_variant_id');
    }

    /**
     * @return HasMany<SavedFrame, $this>
     */
    public function savedFrames(): HasMany
    {
        return $this->hasMany(SavedFrame::class);
    }

    /**
     * @return HasMany<ArAsset, $this>
     */
    public function arAssets(): HasMany
    {
        return $this->hasMany(ArAsset::class);
    }

    /**
     * @return HasOne<ArAsset, $this>
     */
    public function latestArAsset(): HasOne
    {
        return $this->hasOne(ArAsset::class)->latestOfMany('version');
    }

    /**
     * @return BelongsTo<ArAsset, $this>
     */
    public function publishedArAsset(): BelongsTo
    {
        return $this->belongsTo(ArAsset::class, 'published_ar_asset_id')
            ->where('status', ArAssetStatus::Published->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'attributes' => 'array',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'target_stock_level' => 'integer',
            'ar_eligible' => 'boolean',
            'published_ar_asset_id' => 'integer',
            'images' => 'array',
        ];
    }

    /**
     * @return Collection<int, InventoryLot>
     */
    private function inventoryLotsForDisplay(): Collection
    {
        if ($this->relationLoaded('inventoryLots')) {
            return $this->inventoryLots;
        }

        return $this->inventoryLots()->get();
    }

    private static function asOfDate(?CarbonInterface $asOf): CarbonImmutable
    {
        return ($asOf === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($asOf)
        )->startOfDay();
    }
}
