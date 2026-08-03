<?php

namespace App\Models;

use App\Enums\TransactionItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'quotation_id',
    'description',
    'quantity',
    'unit_price',
    'amount',
    'product_variant_id',
    'lens_category_id',
    'item_type',
])]
class QuotationItem extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (QuotationItem $item): void {
            // Service items cannot have catalog references
            if ($item->item_type === TransactionItemType::Service) {
                if ($item->product_variant_id !== null || $item->lens_category_id !== null) {
                    throw new \InvalidArgumentException('Service items cannot reference a product variant or lens category.');
                }
            }

            // Product items with catalog references auto-set item_type
            if ($item->item_type === null && ($item->product_variant_id !== null || $item->lens_category_id !== null)) {
                $item->item_type = TransactionItemType::Product;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'item_type' => TransactionItemType::class,
        ];
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<LensCategory, $this>
     */
    public function lensCategory(): BelongsTo
    {
        return $this->belongsTo(LensCategory::class, 'lens_category_id');
    }
}
