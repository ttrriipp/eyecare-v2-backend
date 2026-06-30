<?php

namespace App\Models;

use Database\Factories\BillingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'customer_id',
    'order_id',
    'appointment_id',
    'discount_type_id',
    'discount_amount',
    'subtotal',
    'billing_number',
    'or_number',
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
            if (empty($billing->or_number)) {
                $billing->or_number = self::generateOrNumber();
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

    private static function generateOrNumber(): string
    {
        $year = now()->format('Y');
        $sequence = self::query()
            ->whereYear('created_at', $year)
            ->withTrashed()
            ->count() + 1;

        return sprintf('OR-%s-%06d', $year, $sequence);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<DiscountType, $this> */
    public function discountType(): BelongsTo
    {
        return $this->belongsTo(DiscountType::class);
    }

    /** @return BelongsTo<BillingStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(BillingStatus::class, 'billing_status_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<BillingItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }
}
