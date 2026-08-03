<?php

namespace App\Models;

use App\Enums\BillingItemSourceKind;
use App\Enums\TransactionItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'billing_record_id',
    'item_type',
    'description',
    'quantity',
    'unit_price',
    'amount',
    'job_order_item_id',
    'quotation_item_id',
    'encounter_id',
    'source_kind',
])]
class BillingRecordItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'item_type' => TransactionItemType::class,
            'source_kind' => BillingItemSourceKind::class,
        ];
    }

    /**
     * @return BelongsTo<BillingRecord, $this>
     */
    public function billingRecord(): BelongsTo
    {
        return $this->belongsTo(BillingRecord::class);
    }

    /**
     * @return BelongsTo<JobOrderItem, $this>
     */
    public function jobOrderItem(): BelongsTo
    {
        return $this->belongsTo(JobOrderItem::class);
    }

    /**
     * @return BelongsTo<QuotationItem, $this>
     */
    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * Get the source label for display.
     */
    public function getSourceLabel(): string
    {
        return match ($this->source_kind) {
            BillingItemSourceKind::OpticalOrder => 'Optical Order',
            BillingItemSourceKind::Quotation => 'Quotation',
            BillingItemSourceKind::Encounter => 'Encounter',
            BillingItemSourceKind::DirectService => 'Direct Service',
            default => 'Unknown',
        };
    }
}
