<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'invoice_number',
    'official_number',
    'patient_id',
    'job_order_id',
    'encounter_id',
    'status',
    'sale_type',
    'sold_to_name',
    'registered_name',
    'tin',
    'business_address',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'total',
    'amount_paid',
    'balance_due',
    'notes',
    'recorded_by',
    'issued_at',
])]
class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            if (blank($invoice->invoice_number)) {
                $year = now()->format('Y');
                $sequence = self::query()->withTrashed()->whereYear('created_at', $year)->count() + 1;
                $invoice->invoice_number = sprintf('INV-%s-%06d', $year, $sequence);
            }

            if ($invoice->balance_due === 0 && $invoice->total > 0) {
                $invoice->balance_due = $invoice->total - $invoice->amount_paid;
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
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<InvoicePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Recalculate amount_paid and balance_due from payment history.
     */
    public function recalculateBalance(): void
    {
        $amountPaid = $this->payments()->sum('amount');
        $balanceDue = max($this->total - $amountPaid, 0);

        $status = match (true) {
            $amountPaid <= 0 => InvoiceStatus::Issued,
            $balanceDue > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Paid,
        };

        $this->update([
            'amount_paid' => $amountPaid,
            'balance_due' => $balanceDue,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
        ];
    }
}
