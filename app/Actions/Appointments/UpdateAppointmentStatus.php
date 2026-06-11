<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class UpdateAppointmentStatus
{
    /**
     * Allowed status transitions: current → permitted next statuses.
     *
     * @var array<string, string[]>
     */
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['confirmed', 'rescheduled', 'cancelled'],
        'confirmed' => ['rescheduled', 'cancelled', 'completed'],
        'rescheduled' => ['confirmed', 'cancelled', 'completed'],
        'cancelled' => [],
        'completed' => [],
    ];

    /**
     * @var array<string, string>
     */
    private const SMS_EVENTS = [
        'confirmed' => 'appointment_confirmed',
        'rescheduled' => 'appointment_rescheduled',
        'cancelled' => 'appointment_cancelled',
    ];

    public function handle(
        Appointment $appointment,
        string $statusName,
        ?Carbon $scheduledAt = null,
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

        if ($statusName === 'rescheduled' && $scheduledAt !== null) {
            $attributes['scheduled_at'] = $scheduledAt;
        }

        $appointment->update($attributes);
        $appointment->load(['customer', 'visitReason', 'status']);

        if (array_key_exists($statusName, self::SMS_EVENTS)) {
            $this->createSmsNotification($appointment, self::SMS_EVENTS[$statusName]);
        }

        app(CreateAuditLog::class)->handle(
            subject: $appointment,
            action: 'appointment.status_changed',
            metadata: ['from' => $currentStatus, 'to' => $statusName],
        );

        return $appointment->fresh(['visitReason', 'status']);
    }

    private function createSmsNotification(Appointment $appointment, string $event): void
    {
        $queuedStatus = NotificationStatus::query()->where('name', 'queued')->firstOrFail();

        SmsNotification::query()->create([
            'appointment_id' => $appointment->id,
            'notification_status_id' => $queuedStatus->id,
            'event' => $event,
            'recipient' => $appointment->customer->phone ?? $appointment->customer->email,
            'message' => $this->buildMessage($appointment, $event),
        ]);
    }

    private function buildMessage(Appointment $appointment, string $event): string
    {
        return match ($event) {
            'appointment_confirmed' => "Your appointment on {$appointment->scheduled_at->toDateTimeString()} has been confirmed.",
            'appointment_rescheduled' => "Your appointment has been rescheduled to {$appointment->scheduled_at->toDateTimeString()}.",
            'appointment_cancelled' => "Your appointment on {$appointment->scheduled_at->toDateTimeString()} has been cancelled.",
        };
    }
}
