<?php

namespace App\Models;

use App\Enums\AccessoryOrderRequestStatus;
use Database\Factories\AccessoryOrderRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'patient_id',
    'status',
    'subtotal_amount',
    'requested_discount_type',
    'job_order_id',
    'resolved_by',
    'resolved_at',
    'rejection_reason',
    'cancelled_at',
])]
class AccessoryOrderRequest extends Model
{
    /** @use HasFactory<AccessoryOrderRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AccessoryOrderRequestStatus::class,
            'subtotal_amount' => 'decimal:2',
            'resolved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AccessoryOrderRequest $request): void {
            if (blank($request->request_number)) {
                $year = now()->format('Y');
                $sequence = self::query()->whereYear('created_at', $year)->count() + 1;
                $request->request_number = sprintf('ORQ-%s-%06d', $year, $sequence);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccessoryOrderRequestItem::class);
    }

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function discountProof(): HasOne
    {
        return $this->hasOne(AccessoryOrderRequestDiscountProof::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isPending(): bool
    {
        return $this->status === AccessoryOrderRequestStatus::Pending;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
