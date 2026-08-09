<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'job_order_id',
    'billing_record_id',
    'dispensed_by',
    'recipient_name',
    'notes',
    'dispensed_at',
    'released_balance_amount',
    'balance_override_by',
    'balance_override_reason',
    'balance_due_date',
])]
class DispensingEvent extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<JobOrder, $this>
     */
    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

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
    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function balanceOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'balance_override_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dispensed_at' => 'datetime',
            'released_balance_amount' => 'decimal:2',
            'balance_due_date' => 'date',
        ];
    }
}
