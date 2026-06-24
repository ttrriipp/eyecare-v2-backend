<?php

namespace App\Models;

use Database\Factories\ServiceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'customer_id',
    'service_id',
    'appointment_id',
    'staff_id',
    'amount',
    'discount_type_id',
    'discount_amount',
    'total_amount',
    'notes',
    'performed_at',
])]
class ServiceRecord extends Model
{
    /** @use HasFactory<ServiceRecordFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * @return BelongsTo<DiscountType, $this>
     */
    public function discountType(): BelongsTo
    {
        return $this->belongsTo(DiscountType::class);
    }

    /**
     * @return MorphOne<Billing, $this>
     */
    public function billing(): MorphOne
    {
        return $this->morphOne(Billing::class, 'billable');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'performed_at' => 'datetime',
        ];
    }
}
