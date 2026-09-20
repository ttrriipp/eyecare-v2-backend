<?php

namespace App\Models;

use App\Enums\CommercialItemKind;
use Database\Factories\AccessoryOrderRequestItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'accessory_order_request_id',
    'product_variant_id',
    'description',
    'quantity',
    'unit_price',
    'amount',
    'item_kind',
    'item_snapshot',
])]
class AccessoryOrderRequestItem extends Model
{
    /** @use HasFactory<AccessoryOrderRequestItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'item_kind' => CommercialItemKind::class,
            'item_snapshot' => 'array',
        ];
    }

    public function accessoryOrderRequest(): BelongsTo
    {
        return $this->belongsTo(AccessoryOrderRequest::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
