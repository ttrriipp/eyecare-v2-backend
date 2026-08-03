<?php

namespace App\Models;

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
    'encounter_id',
])]
class BillingRecordItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'item_type' => TransactionItemType::class,
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
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * Check if this item originated from an Optical Order.
     */
    public function isFromOpticalOrder(): bool
    {
        return $this->job_order_item_id !== null;
    }

    /**
     * Check if this item originated from an Encounter.
     */
    public function isFromEncounter(): bool
    {
        return $this->encounter_id !== null && $this->job_order_item_id === null;
    }
}
