<?php

namespace App\Models;

use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
            'ar_eligible' => 'boolean',
            'images' => 'array',
        ];
    }
}
