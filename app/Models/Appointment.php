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
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'customer_id',
    'staff_id',
    'visit_reason_id',
    'appointment_status_id',
    'scheduled_at',
    'contact_notes',
    'staff_notes',
])]
class Appointment extends Model implements Eventable
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, SoftDeletes;

    public function toCalendarEvent(): CalendarEvent
    {
        $color = match ($this->status?->name) {
            'confirmed' => '#3b82f6',
            'rescheduled' => '#f59e0b',
            'completed' => '#22c55e',
            'cancelled' => '#ef4444',
            default => '#6b7280',
        };

        return CalendarEvent::make($this)
            ->title($this->customer?->name ?? 'Appointment')
            ->start($this->scheduled_at)
            ->end($this->scheduled_at->addMinutes($this->visitReason?->duration_minutes ?? 30))
            ->backgroundColor($color);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * @return BelongsTo<VisitReason, $this>
     */
    public function visitReason(): BelongsTo
    {
        return $this->belongsTo(VisitReason::class);
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
     * Uses the existing appointment's visit reason duration for its end time,
     * and the provided $durationMinutes for the proposed slot's end time.
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
                'DATE_ADD(scheduled_at, INTERVAL COALESCE((SELECT duration_minutes FROM visit_reasons WHERE visit_reasons.id = appointments.visit_reason_id), 30) MINUTE) > ?',
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
        ];
    }
}
