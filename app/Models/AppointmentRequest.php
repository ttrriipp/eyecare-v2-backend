<?php

namespace App\Models;

use App\Enums\AppointmentRequestStatus;
use Database\Factories\AppointmentRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentRequest extends Model
{
    /** @use HasFactory<AppointmentRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'request_number',
        'user_id',
        'patient_id',
        'appointment_type_id',
        'appointment_id',
        'scheduled_at',
        'provisional_duration_minutes',
        'encrypted_reason_for_visit',
        'encrypted_identity_snapshot',
        'status',
        'expires_at',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentRequestStatus::class,
            'scheduled_at' => 'datetime',
            'encrypted_reason_for_visit' => 'encrypted',
            'encrypted_identity_snapshot' => 'encrypted:array',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
            'provisional_duration_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AppointmentRequest $request): void {
            if (blank($request->request_number)) {
                $year = now()->format('Y');
                $sequence = self::query()->whereYear('created_at', $year)->count() + 1;
                $request->request_number = sprintf('APR-%s-%06d', $year, $sequence);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<AppointmentType, $this>
     */
    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
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
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === AppointmentRequestStatus::Pending
            && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->status === AppointmentRequestStatus::Accepted;
    }

    public function isCancelled(): bool
    {
        return $this->status === AppointmentRequestStatus::Cancelled;
    }

    public function needsPatientResolution(): bool
    {
        return $this->patient_id === null;
    }

    public function isReadyForScheduleReview(): bool
    {
        return $this->patient_id !== null
            && $this->status === AppointmentRequestStatus::Pending;
    }
}
