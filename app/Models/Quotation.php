<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'quotation_number',
    'patient_id',
    'encounter_id',
    'prescription_id',
    'frame_reservation_id',
    'status',
    'valid_until',
    'subtotal',
    'discount_amount',
    'total',
    'presented_by',
    'presented_at',
    'confirmed_by',
    'confirmed_at',
    'decline_reason',
    'notes',
    'internal_notes',
    'eyewear_key',
])]
class Quotation extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation): void {
            if (blank($quotation->quotation_number)) {
                $year = now()->format('Y');
                $sequence = self::query()->withTrashed()->whereYear('created_at', $year)->count() + 1;
                $quotation->quotation_number = sprintf('QUO-%s-%06d', $year, $sequence);
            }

            if (blank($quotation->eyewear_key)) {
                $quotation->eyewear_key = 'eyw_'.Str::ulid();
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
     * @return BelongsTo<FrameReservation, $this>
     */
    public function frameReservation(): BelongsTo
    {
        return $this->belongsTo(FrameReservation::class);
    }

    /**
     * Direct items relationship.
     *
     * @return HasMany<QuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function productItems(): HasMany
    {
        return $this->items()->where('item_type', TransactionItemType::Product);
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function serviceItems(): HasMany
    {
        return $this->items()->where('item_type', TransactionItemType::Service);
    }

    /**
     * Quoted service lines not yet snapshotted onto any Billing Record —
     * either skipped at confirm-sale time or added to the quotation after.
     *
     * @return HasMany<QuotationItem, $this>
     */
    public function unbilledServiceItems(): HasMany
    {
        return $this->serviceItems()->whereDoesntHave('billingRecordItems');
    }

    /**
     * Direct job order relationship.
     *
     * @return HasOne<JobOrder, $this>
     */
    public function jobOrder(): HasOne
    {
        return $this->hasOne(JobOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function presenter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presented_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'presented_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
