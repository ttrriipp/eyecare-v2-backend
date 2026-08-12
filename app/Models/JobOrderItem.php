<?php

namespace App\Models;

use App\Enums\CommercialItemKind;
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
    'lens_option_id',
    'item_kind',
    'item_snapshot',
])]
class JobOrderItem extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (JobOrderItem $item): void {
            $catalogReferenceCount = collect([
                $item->product_variant_id,
                $item->lens_category_id,
                $item->lens_option_id,
            ])->filter(fn (mixed $reference): bool => $reference !== null)->count();

            if ($catalogReferenceCount > 1) {
                throw new \InvalidArgumentException('Optical order items can reference only one catalog entry.');
            }

            // Job Order items must be Product type
            if (! in_array($item->item_kind?->value, CommercialItemKind::productKindValues(), true)) {
                throw new \InvalidArgumentException('Job Order items must be Product type.');
            }

            if ($item->lens_option_id !== null
                && $item->item_kind !== null
                && $item->item_kind !== CommercialItemKind::LensOption) {
                throw new \InvalidArgumentException('Lens option items must have the LensOption item kind.');
            }

            if ($item->lens_option_id !== null && $item->item_kind === null) {
                $item->item_kind = CommercialItemKind::LensOption;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'item_kind' => CommercialItemKind::class,
            'item_snapshot' => 'array',
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

    /**
     * @return BelongsTo<LensOption, $this>
     */
    public function lensOption(): BelongsTo
    {
        return $this->belongsTo(LensOption::class);
    }
}
