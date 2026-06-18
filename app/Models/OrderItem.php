<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'product_variant_id',
    'lens_type_id',
    'lens_product_variant_id',
    'product_id',
    'product_name',
    'variant_name',
    'variant_sku',
    'lens_type_name',
    'lens_type_price',
    'unit_price',
    'quantity',
    'subtotal',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * @return BelongsTo<LensType, $this>
     */
    public function lensType(): BelongsTo
    {
        return $this->belongsTo(LensType::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function lensProductVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'lens_product_variant_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'lens_type_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'quantity' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
