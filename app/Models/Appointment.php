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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
    'appointment_status_id',
    'scheduled_at',
    'checked_in_at',
    'checked_in_by',
    'fulfilled_at',
    'cancelled_by',
    'cancelled_by_user_id',
    'cancellation_reason_category',
    'cancellation_reason_details',
    'cancelled_at',
    'no_show_by',
    'no_show_at',
    'contact_notes',
    'staff_notes',
    'reason_for_visit',
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
            'scheduled' => '#3b82f6',
            'checked_in' => '#f59e0b',
            'fulfilled' => '#22c55e',
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
            ->end($this->scheduled_at->copy()->addMinutes($this->duration_minutes ?? 30))
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
     * @return BelongsTo<AppointmentType, $this>
     */
    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    /**
     * @return HasOne<FrameReservation, $this>
     */
    public function frameReservation(): HasOne
    {
        return $this->hasOne(FrameReservation::class);
    }

    /**
     * @deprecated Use frameReservation() instead
     *
     * @return HasOne<FrameReservation, $this>
     */
    public function frameReservations(): HasOne
    {
        return $this->frameReservation();
    }

    /**
     * @return HasManyThrough<FrameReservationItem, FrameReservation, $this>
     */
    public function frameReservationItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            FrameReservationItem::class,
            FrameReservation::class,
            'appointment_id',
            'frame_reservation_id',
            'id',
            'id',
        );
    }

    /**
     * @return HasMany<Encounter, $this>
     */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    /**
     * @return HasOne<VisitRating, $this>
     */
    public function visitRating(): HasOne
    {
        return $this->hasOne(VisitRating::class);
    }

    /**
     * @return HasOne<Encounter, $this>
     */
    public function encounter(): HasOne
    {
        return $this->hasOne(Encounter::class);
    }

    /**
     * @return HasMany<AppointmentReschedule, $this>
     */
    public function reschedules(): HasMany
    {
        return $this->hasMany(AppointmentReschedule::class)->latest('rescheduled_at');
    }

    /**
     * @return HasOne<AppointmentReschedule, $this>
     */
    public function latestReschedule(): HasOne
    {
        return $this->hasOne(AppointmentReschedule::class)->latestOfMany('rescheduled_at');
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
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
        ];
    }
}
