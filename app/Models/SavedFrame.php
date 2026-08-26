<?php

namespace App\Models;

use Database\Factories\SavedFrameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'product_variant_id',
])]
class SavedFrame extends Model
{
    /** @use HasFactory<SavedFrameFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Eager-load catalog records even after a product or variant is soft-deleted.
     *
     * @param  Builder<SavedFrame>  $query
     * @return Builder<SavedFrame>
     */
    public function scopeWithCatalogData(Builder $query): Builder
    {
        return $query->with([
            'variant' => fn (BelongsTo $variantQuery) => $variantQuery->withTrashed(),
            'variant.publishedArAsset',
            'variant.product' => fn (BelongsTo $productQuery) => $productQuery->withTrashed(),
            'variant.product.brand' => fn (BelongsTo $brandQuery) => $brandQuery->withTrashed(),
            'variant.product.category' => fn (BelongsTo $categoryQuery) => $categoryQuery->withTrashed(),
        ]);
    }
}
