<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'brand_id',
    'category_id',
    'name',
    'slug',
    'description',
    'is_active',
    'product_type',
    'images',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, string> */
    public const array TYPE_OPTIONS = [
        'frame' => 'Frame',
        'contact_lens' => 'Contact Lens',
        'accessory' => 'Accessory',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            if (empty($product->slug)) {
                $product->slug = self::generateUniqueSlug($product->name);
            }
        });
    }

    private static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $count = 1;

        while (self::query()->where('slug', $slug)->withTrashed()->exists()) {
            $slug = "{$base}-{$count}";
            $count++;
        }

        return $slug;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereHas('brand', fn (Builder $brandQuery): Builder => $brandQuery->active())
            ->where(fn (Builder $categoryQuery): Builder => $categoryQuery
                ->whereNull('category_id')
                ->orWhereHas('category', fn (Builder $query): Builder => $query->active()));
    }

    /**
     * Mobile catalog: active frames only with at least one AR-ready variant.
     */
    public function scopeVisibleInMobileCatalog(Builder $query): void
    {
        $query
            ->active()
            ->where('product_type', 'frame')
            ->whereHas(
                'variants',
                fn (Builder $variantQuery): Builder => $variantQuery->arReady(),
            );
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'images' => 'array',
        ];
    }
}
