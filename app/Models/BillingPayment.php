<?php

namespace App\Models;

use Database\Factories\BillingPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'billing_record_id',
    'amount',
    'payment_method',
    'reference_number',
    'status',
    'recorded_by',
    'recorded_at',
    'notes',
    'reversed_by',
    'reversed_at',
    'reversal_reason',
])]
class BillingPayment extends Model
{
    /** @use HasFactory<BillingPaymentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<BillingRecord, $this>
     */
    public function billingRecord(): BelongsTo
    {
        return $this->belongsTo(BillingRecord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recorded_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }
}
