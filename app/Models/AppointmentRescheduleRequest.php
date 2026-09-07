<?php

namespace App\Models;

use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentRescheduleRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'request_number',
    'appointment_id',
    'user_id',
    'patient_id',
    'current_scheduled_at',
    'requested_scheduled_at',
    'alternative_scheduled_times',
    'encrypted_reason_details',
    'status',
    'selected_scheduled_at',
    'resolved_by_user_id',
    'resolved_at',
    'rejection_reason',
    'expires_at',
    'appointment_reschedule_id',
])]
class AppointmentRescheduleRequest extends Model
{
    /** @use HasFactory<AppointmentRescheduleRequestFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (AppointmentRescheduleRequest $request): void {
            if (blank($request->request_number)) {
                $request->request_number = self::generateRequestNumber();
            }
        });
    }

    public static function generateRequestNumber(): string
    {
        $year = now()->format('Y');
        $sequence = self::query()
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf('ARR-%s-%06d', $year, $sequence);
    }

    public function effectiveStatus(): AppointmentRescheduleRequestStatus
    {
        if ($this->status !== AppointmentRescheduleRequestStatus::Pending) {
            return $this->status;
        }

        if ($this->expires_at instanceof CarbonInterface && $this->expires_at->lte(now())) {
            return AppointmentRescheduleRequestStatus::Expired;
        }

        $appointment = $this->relationLoaded('appointment')
            ? $this->getRelation('appointment')
            : $this->appointment()->with('status')->first();

        if ($appointment === null
            || $appointment->status?->name !== AppointmentStatusName::Scheduled->value
            || ! $appointment->scheduled_at instanceof CarbonInterface
            || $appointment->scheduled_at->lte(now())
            || ! $this->current_scheduled_at instanceof CarbonInterface
            || ! $appointment->scheduled_at->equalTo($this->current_scheduled_at)) {
            return AppointmentRescheduleRequestStatus::Expired;
        }

        return AppointmentRescheduleRequestStatus::Pending;
    }

    public function isPending(): bool
    {
        return $this->effectiveStatus() === AppointmentRescheduleRequestStatus::Pending;
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
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * @return BelongsTo<AppointmentReschedule, $this>
     */
    public function appointmentReschedule(): BelongsTo
    {
        return $this->belongsTo(AppointmentReschedule::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppointmentRescheduleRequestStatus::class,
            'current_scheduled_at' => 'datetime',
            'requested_scheduled_at' => 'datetime',
            'alternative_scheduled_times' => 'array',
            'encrypted_reason_details' => 'encrypted',
            'selected_scheduled_at' => 'datetime',
            'resolved_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
