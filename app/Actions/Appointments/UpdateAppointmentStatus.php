<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Notifications\AppointmentStatusChanged;
use Illuminate\Validation\ValidationException;

class UpdateAppointmentStatus
{
    /**
     * Allowed status transitions: current → permitted next statuses.
     *
     * @var array<string, string[]>
     */
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['arrived', 'no_show', 'cancelled'],
        'arrived' => ['completed', 'cancelled'],
        'cancelled' => [],
        'completed' => [],
        'no_show' => [],
        // New lifecycle bridge (temporary until Task 7)
        'scheduled' => ['checked_in', 'cancelled', 'no_show'],
        'checked_in' => ['fulfilled', 'cancelled'],
        'fulfilled' => [],
    ];

    /**
     * @var array<string, string>
     */
    private const SMS_EVENTS = [
        'confirmed' => 'appointment_confirmed',
        'cancelled' => 'appointment_cancelled',
    ];

    public function handle(
        Appointment $appointment,
        string $statusName,
        ?string $staffNotes = null,
    ): Appointment {
        $currentStatus = $appointment->status->name;
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($statusName, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition appointment from '{$currentStatus}' to '{$statusName}'."],
            ]);
        }

        $status = AppointmentStatus::query()->where('name', $statusName)->firstOrFail();

        $attributes = [
            'appointment_status_id' => $status->id,
        ];

        if ($staffNotes !== null) {
            $attributes['staff_notes'] = $staffNotes;
        }

        if ($statusName === 'arrived' || $statusName === 'checked_in') {
            $attributes['checked_in_at'] = now();
            $attributes['checked_in_by'] = auth()->id();
        }

        if ($statusName === 'completed' || $statusName === 'fulfilled') {
            $attributes['fulfilled_at'] = now();
        }

        $appointment->update($attributes);
        $appointment->load(['patient', 'appointmentType', 'status']);

        if (array_key_exists($statusName, self::SMS_EVENTS)) {
            $this->createSmsNotification($appointment, self::SMS_EVENTS[$statusName]);
            $appointment->patient->account?->notify(new AppointmentStatusChanged($appointment));
        }

        app(CreateAuditLog::class)->handle(
            subject: $appointment,
            action: 'appointment.status_changed',
            metadata: ['from' => $currentStatus, 'to' => $statusName],
        );

        return $appointment->fresh(['appointmentType', 'status']);
    }

    private function createSmsNotification(Appointment $appointment, string $event): void
    {
        $queuedStatus = NotificationStatus::query()->where('name', 'queued')->firstOrFail();

        SmsNotification::query()->create([
            'appointment_id' => $appointment->id,
            'notification_status_id' => $queuedStatus->id,
            'event' => $event,
            'recipient' => $appointment->patient->phone ?? $appointment->patient->contact_email,
            'message' => $this->buildMessage($appointment, $event),
        ]);
    }

    private function buildMessage(Appointment $appointment, string $event): string
    {
        return match ($event) {
            'appointment_confirmed' => "Your appointment {$appointment->appointment_number} on {$appointment->scheduled_at->toDateTimeString()} has been confirmed.",
            'appointment_cancelled' => "Your appointment {$appointment->appointment_number} on {$appointment->scheduled_at->toDateTimeString()} has been cancelled.",
        };
    }
}
