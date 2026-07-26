<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Guava\Calendar\Contracts\Eventable;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'appointment_number',
    'patient_id',
    'appointment_type_id',
    'duration_minutes',
    'referring_source',
    'created_by',
    'optometrist_id',
    'source',
    'visit_reason_id',
    'appointment_status_id',
    'scheduled_at',
    'checked_in_at',
    'checked_in_by',
    'completed_at',
    'contact_notes',
    'staff_notes',
    'last_reschedule_reason',
])]
class Appointment extends Model implements Eventable
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment): void {
            if (empty($appointment->appointment_number)) {
                $appointment->appointment_number = self::generateAppointmentNumber();
            }

            if ($appointment->created_by === null && auth()->check()) {
                $appointment->created_by = auth()->id();
            }
        });
    }

    public static function generateAppointmentNumber(): string
    {
        $year = now()->format('Y');
        $sequence = self::query()
            ->whereYear('created_at', $year)
            ->withTrashed()
            ->count() + 1;

        return sprintf('APT-%s-%06d', $year, $sequence);
    }

    public function toCalendarEvent(): CalendarEvent
    {
        $color = match ($this->status?->name) {
            'confirmed' => '#3b82f6',
            'arrived' => '#f59e0b',
            'completed' => '#22c55e',
            'no_show' => '#6b7280',
            'cancelled' => '#ef4444',
            default => '#6b7280',
        };

        $title = $this->patient?->full_name ?? 'Appointment';
        $phone = $this->patient?->phone;
        $reason = $this->appointmentType?->name;

        if ($phone) {
            $title .= " · {$phone}";
        }

        if ($reason) {
            $title .= " — {$reason}";
        }

        return CalendarEvent::make($this)
            ->title($title)
            ->start($this->scheduled_at)
            ->end($this->scheduled_at->addMinutes($this->duration_minutes ?? 30))
            ->backgroundColor($color);
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function optometrist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'optometrist_id');
    }

    /**
     * @return BelongsTo<VisitReason, $this>
     */
    public function visitReason(): BelongsTo
    {
        return $this->belongsTo(VisitReason::class);
    }

    /**
     * @return BelongsTo<AppointmentType, $this>
     */
    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    /**
     * @return HasOne<PatientIntake, $this>
     */
    public function intake(): HasOne
    {
        return $this->hasOne(PatientIntake::class);
    }

    /**
     * @return HasMany<Encounter, $this>
     */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    /**
     * @return BelongsTo<AppointmentStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(AppointmentStatus::class, 'appointment_status_id');
    }

    /**
     * Whether a non-cancelled appointment overlaps with the given time range.
     *
     * Uses the existing appointment's booked duration_minutes snapshot for its
     * end time, and the provided $durationMinutes for the proposed slot's end time.
     *
     * @param  int  $durationMinutes  Duration of the proposed appointment.
     * @param  int|null  $ignoreId  An appointment id to exclude (e.g. the one being rescheduled).
     */
    public static function conflictsWith(CarbonInterface $at, int $durationMinutes = 30, ?int $ignoreId = null): bool
    {
        $proposedStart = $at->copy();
        $proposedEnd = $at->copy()->addMinutes($durationMinutes);

        return static::query()
            ->whereHas('status', fn ($query) => $query->where('name', '!=', 'cancelled'))
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            // Overlap: existing_start < proposed_end AND proposed_start < existing_end
            ->where('scheduled_at', '<', $proposedEnd)
            ->whereRaw(
                'DATE_ADD(scheduled_at, INTERVAL COALESCE(duration_minutes, 30) MINUTE) > ?',
                [$proposedStart],
            )
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
