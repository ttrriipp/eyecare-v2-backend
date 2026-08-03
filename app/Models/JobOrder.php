<?php

namespace App\Models;

use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'job_order_number',
    'patient_id',
    'encounter_id',
    'prescription_id',
    'quotation_id',
    'frame_reservation_id',
    'status',
    'fulfillment_mode',
    'uses_external_supplier',
    'total_amount',
    'notes',
    'supplier_invoice_number',
    'eyewear_key',
    'started_at',
    'ready_at',
    'dispensed_at',
    'cancelled_at',
])]
#[Hidden(['supplier_invoice_number'])]
class JobOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (JobOrder $jobOrder): void {
            if (blank($jobOrder->job_order_number)) {
                $year = now()->format('Y');
                $sequence = self::query()->withTrashed()->whereYear('created_at', $year)->count() + 1;
                $jobOrder->job_order_number = sprintf('JO-%s-%06d', $year, $sequence);
            }

            if (blank($jobOrder->eyewear_key)) {
                $jobOrder->eyewear_key = 'eyw_'.Str::ulid();
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
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return HasMany<JobOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(JobOrderItem::class);
    }

    /**
     * @return HasOne<BillingRecord, $this>
     */
    public function billingRecord(): HasOne
    {
        return $this->hasOne(BillingRecord::class);
    }

    /**
     * Get the active (non-voided) billing record.
     *
     * @return HasOne<BillingRecord, $this>
     */
    public function activeBillingRecord(): HasOne
    {
        return $this->hasOne(BillingRecord::class)
            ->where('status', '!=', BillingRecordStatus::Voided)
            ->latest('id');
    }

    /**
     * @return BelongsTo<FrameReservation, $this>
     */
    public function frameReservation(): BelongsTo
    {
        return $this->belongsTo(FrameReservation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobOrderStatus::class,
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'dispensed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
