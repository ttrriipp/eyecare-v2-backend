<?php

namespace App\Models;

use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        $query->where('is_active', true);
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
        $query
            ->where('low_stock_threshold', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
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

        return max($this->replenishmentTarget() - $this->stock_quantity, 0);
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
                        ->where('is_active', true)
                        ->where('product_type', 'accessory'),
                )
                ->orWhere(fn (Builder $frameVariantQuery): Builder => $frameVariantQuery
                    ->where('ar_eligible', true)
                    ->whereNotNull('ar_asset_reference')
                    ->whereHas(
                        'product',
                        fn (Builder $productQuery): Builder => $productQuery
                            ->where('is_active', true)
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
     * @return HasMany<FrameRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(FrameRating::class, 'product_variant_id');
    }

    /**
     * @return HasMany<InventoryLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class, 'product_variant_id');
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
            'images' => 'array',
        ];
    }
}
