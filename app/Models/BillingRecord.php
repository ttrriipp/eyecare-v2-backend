<?php

namespace App\Models;

use App\Enums\BillingRecordStatus;
use Database\Factories\BillingRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'billing_record_number',
    'patient_id',
    'job_order_id',
    'encounter_id',
    'status',
    'total_amount',
    'amount_paid',
    'balance_due',
    'notes',
    'recorded_by',
    'recorded_at',
    'voided_by',
    'voided_at',
    'void_reason',
])]
class BillingRecord extends Model
{
    /** @use HasFactory<BillingRecordFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (BillingRecord $record): void {
            if (blank($record->billing_record_number)) {
                $year = now()->format('Y');
                $sequence = self::query()
                    ->withTrashed()
                    ->whereYear('created_at', $year)
                    ->count() + 1;
                $record->billing_record_number = sprintf('BR-%s-%06d', $year, $sequence);
            }
        });
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<JobOrder, $this>
     */
    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
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
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return HasMany<BillingPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    /**
     * @return HasMany<BillingPayment, $this>
     */
    public function postedPayments(): HasMany
    {
        return $this->hasMany(BillingPayment::class)->where('status', 'posted');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BillingRecordStatus::class,
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'recorded_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
