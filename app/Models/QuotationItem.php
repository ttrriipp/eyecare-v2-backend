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
