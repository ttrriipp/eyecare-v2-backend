<?php

namespace App\Models;

use App\Enums\CommercialItemKind;
use App\Enums\TransactionItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'quotation_id',
    'description',
    'quantity',
    'unit_price',
    'amount',
    'product_variant_id',
    'lens_category_id',
    'lens_option_id',
    'service_id',
    'item_type',
    'item_kind',
    'item_snapshot',
])]
class QuotationItem extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (QuotationItem $item): void {
            $catalogReferenceCount = collect([
                $item->product_variant_id,
                $item->lens_category_id,
                $item->lens_option_id,
                $item->service_id,
            ])->filter(fn (mixed $reference): bool => $reference !== null)->count();

            if ($catalogReferenceCount > 1) {
                throw new \InvalidArgumentException('Quotation items can reference only one catalog entry.');
            }

            // Service items cannot have catalog references
            if ($item->item_type === TransactionItemType::Service) {
                if ($item->product_variant_id !== null || $item->lens_category_id !== null) {
                    throw new \InvalidArgumentException('Service items cannot reference a product variant or lens category.');
                }

                if ($item->lens_option_id !== null) {
                    throw new \InvalidArgumentException('Service items cannot reference a lens option.');
                }
            }

            // Product items cannot reference a service catalog entry
            if ($item->item_type === TransactionItemType::Product && $item->service_id !== null) {
                throw new \InvalidArgumentException('Product items cannot reference a service.');
            }

            // Product items with catalog references auto-set item_type
            if ($item->item_type === null && ($item->product_variant_id !== null || $item->lens_category_id !== null || $item->lens_option_id !== null)) {
                $item->item_type = TransactionItemType::Product;
            }

            if ($item->lens_option_id !== null && $item->item_type !== TransactionItemType::Product) {
                throw new \InvalidArgumentException('Lens option items must be Product items.');
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
            'item_type' => TransactionItemType::class,
            'item_kind' => CommercialItemKind::class,
            'item_snapshot' => 'array',
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

    /**
     * @return BelongsTo<LensOption, $this>
     */
    public function lensOption(): BelongsTo
    {
        return $this->belongsTo(LensOption::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<BillingRecordItem, $this>
     */
    public function billingRecordItems(): HasMany
    {
        return $this->hasMany(BillingRecordItem::class);
    }
}
