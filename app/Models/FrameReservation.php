<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Database\Factories\FrameReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'patient_id',
    'appointment_id',
    'status',
    'staff_notes',
    'expires_at',
    'released_at',
    'released_by',
    'release_reason',
])]
class FrameReservation extends Model
{
    /** @use HasFactory<FrameReservationFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return HasMany<FrameReservationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FrameReservationItem::class);
    }

    /**
     * @return HasOne<JobOrder, $this>
     */
    public function jobOrder(): HasOne
    {
        return $this->hasOne(JobOrder::class, 'frame_reservation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
