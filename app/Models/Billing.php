<?php

namespace App\Models;

use Database\Factories\BillingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'billable_type',
    'billable_id',
    'billing_number',
    'billing_status_id',
    'total_amount',
    'amount_paid',
    'balance_due',
    'issued_at',
])]
class Billing extends Model
{
    /** @use HasFactory<BillingFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $billing): void {
            if (empty($billing->billing_number)) {
                $billing->billing_number = self::generateBillingNumber();
            }
        });
    }

    private static function generateBillingNumber(): string
    {
        $year = now()->format('Y');
        $sequence = self::query()
            ->whereYear('created_at', $year)
            ->withTrashed()
            ->count() + 1;

        return sprintf('BIL-%s-%06d', $year, $sequence);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<BillingStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(BillingStatus::class, 'billing_status_id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }
}
