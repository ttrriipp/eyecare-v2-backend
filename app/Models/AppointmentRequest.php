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
        'rejection_reason',
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

    /**
     * Check if this request has an identity snapshot (unlinked at submission).
     */
    public function hasIdentitySnapshot(): bool
    {
        return $this->encrypted_identity_snapshot !== null;
    }

    /**
     * Get the display name from the identity snapshot.
     *
     * Returns null for linked requests (no snapshot).
     */
    public function getSnapshotDisplayName(): ?string
    {
        $snapshot = $this->encrypted_identity_snapshot;

        if ($snapshot === null) {
            return null;
        }

        $parts = array_filter([
            $snapshot['first_name'] ?? null,
            $snapshot['middle_name'] ?? null,
            $snapshot['last_name'] ?? null,
        ]);

        return implode(' ', $parts) ?: null;
    }

    /**
     * Get the unmasked phone from the identity snapshot, for staff use.
     */
    public function getSnapshotPhone(): ?string
    {
        return $this->getSnapshotValue('phone');
    }

    /**
     * Get the unmasked optional email from the identity snapshot, for staff use.
     */
    public function getSnapshotEmail(): ?string
    {
        return $this->getSnapshotValue('email');
    }

    /**
     * Get the date of birth from the identity snapshot.
     *
     * Returns null for linked requests (no snapshot).
     */
    public function getSnapshotDateOfBirth(): ?string
    {
        $snapshot = $this->encrypted_identity_snapshot;

        if ($snapshot === null) {
            return null;
        }

        return $snapshot['date_of_birth'] ?? null;
    }

    /**
     * Get a demographic value from the identity snapshot.
     */
    public function getSnapshotGender(): ?string
    {
        return $this->getSnapshotValue('gender');
    }

    public function getSnapshotOccupation(): ?string
    {
        return $this->getSnapshotValue('occupation');
    }

    public function getSnapshotAddress(): ?string
    {
        return $this->getSnapshotValue('address');
    }

    private function getSnapshotValue(string $key): ?string
    {
        $snapshot = $this->encrypted_identity_snapshot;

        if ($snapshot === null || ! isset($snapshot[$key]) || ! is_string($snapshot[$key])) {
            return null;
        }

        return $snapshot[$key];
    }
}
