<?php

namespace App\Models;

use App\Enums\TransactionItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'job_order_id',
    'description',
    'quantity',
    'unit_price',
    'amount',
    'product_variant_id',
    'lens_category_id',
    'item_type',
])]
class JobOrderItem extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (JobOrderItem $item): void {
            // Job Order items must be Product type
            if ($item->item_type !== TransactionItemType::Product) {
                throw new \InvalidArgumentException('Job Order items must be Product type.');
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
     * @return BelongsTo<JobOrder, $this>
     */
    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
