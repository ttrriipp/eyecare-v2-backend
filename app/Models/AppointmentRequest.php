<?php

namespace App\Models;

use App\Enums\AppointmentRequestKind;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AppointmentStatusName;
use Database\Factories\AppointmentRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentRequest extends Model
{
    /** @use HasFactory<AppointmentRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'request_number',
        'request_type',
        'user_id',
        'patient_id',
        'appointment_type_id',
        'appointment_id',
        'original_scheduled_at',
        'selected_scheduled_at',
        'scheduled_at',
        'alternative_scheduled_times',
        'provisional_duration_minutes',
        'encrypted_reason_for_visit',
        'encrypted_referring_source',
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
            'request_type' => AppointmentRequestKind::class,
            'status' => AppointmentRequestStatus::class,
            'scheduled_at' => 'datetime',
            'original_scheduled_at' => 'datetime',
            'selected_scheduled_at' => 'datetime',
            'alternative_scheduled_times' => 'array',
            'encrypted_reason_for_visit' => 'encrypted',
            'encrypted_referring_source' => 'encrypted',
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
            && ! $this->isExpired()
            && ! $this->isStaleRebooking();
    }

    /**
     * Scope requests that are still actionable by staff or count toward the
     * account's active-request limit.
     *
     * @param  Builder<AppointmentRequest>  $query
     * @return Builder<AppointmentRequest>
     */
    public function scopeActionablePending(Builder $query): Builder
    {
        return $query
            ->where('status', AppointmentRequestStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->where(function (Builder $query): void {
                $query
                    ->where('request_type', '!=', AppointmentRequestKind::Reschedule->value)
                    ->orWhereNull('request_type')
                    ->orWhereHas('appointment', function (Builder $appointmentQuery): void {
                        $appointmentQuery->whereHas(
                            'status',
                            fn (Builder $statusQuery): Builder => $statusQuery->where(
                                'name',
                                AppointmentStatusName::Scheduled->value,
                            ),
                        );
                    });
            });
    }

    public function isRebooking(): bool
    {
        return $this->request_type === AppointmentRequestKind::Reschedule;
    }

    public function isStaleRebooking(): bool
    {
        if (! $this->isRebooking()) {
            return false;
        }

        if ($this->appointment_id === null) {
            return true;
        }

        $appointment = $this->relationLoaded('appointment')
            ? $this->appointment
            : $this->appointment()->with('status')->first();

        return $appointment === null
            || $appointment->status?->name !== AppointmentStatusName::Scheduled->value;
    }

    public function effectiveStatus(): AppointmentRequestStatus
    {
        if ($this->status === AppointmentRequestStatus::Pending
            && ($this->isExpired() || $this->isStaleRebooking())) {
            return AppointmentRequestStatus::Expired;
        }

        return $this->status;
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
        return $this->patient_id !== null && $this->isPending();
    }

    public function hasIdentitySnapshot(): bool
    {
        return $this->encrypted_identity_snapshot !== null;
    }

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

    public function getSnapshotPhone(): ?string
    {
        return $this->getSnapshotValue('phone');
    }

    public function getSnapshotEmail(): ?string
    {
        return $this->getSnapshotValue('email');
    }

    public function getSnapshotDateOfBirth(): ?string
    {
        $snapshot = $this->encrypted_identity_snapshot;

        if ($snapshot === null) {
            return null;
        }

        return $snapshot['date_of_birth'] ?? null;
    }

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

    /**
     * Get all submitted time preferences (primary + alternatives) in order.
     *
     * @return array<int, string>
     */
    public function getAllTimePreferences(): array
    {
        $times = [$this->scheduled_at->toISOString()];

        if ($this->alternative_scheduled_times !== null) {
            $times = array_merge($times, $this->alternative_scheduled_times);
        }

        return $times;
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
