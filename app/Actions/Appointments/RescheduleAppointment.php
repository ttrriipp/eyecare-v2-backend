<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Notifications\AppointmentRescheduled;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleAppointment
{
    public function __construct(
        private readonly ScheduleAppointment $scheduleAppointment,
        private readonly CreateAuditLog $createAuditLog,
    ) {}

    public function handle(
        Appointment $appointment,
        CarbonInterface $scheduledAt,
        bool $customerInitiated,
    ): Appointment {
        if (! in_array($appointment->status->name, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ['This appointment cannot be rescheduled.'],
            ]);
        }

        $appointment->loadMissing(['visitReason', 'optometrist', 'customer']);

        $this->scheduleAppointment->handle(
            scheduledAt: $scheduledAt,
            visitReason: $appointment->visitReason,
            optometrist: $appointment->optometrist,
            ignoreAppointment: $appointment,
        );

        return DB::transaction(function () use ($appointment, $scheduledAt, $customerInitiated): Appointment {
            $previousScheduledAt = $appointment->scheduled_at->toDateTimeString();
            $attributes = ['scheduled_at' => $scheduledAt];

            if ($customerInitiated) {
                $attributes['appointment_status_id'] = AppointmentStatus::query()
                    ->where('name', 'pending')
                    ->value('id');
            }

            $appointment->update($attributes);
            $appointment->load(['customer', 'visitReason', 'status', 'optometrist']);

            $this->createSmsNotification($appointment);
            $appointment->customer->notify(new AppointmentRescheduled($appointment));
            $this->createAuditLog->handle(
                subject: $appointment,
                action: 'appointment.rescheduled',
                metadata: [
                    'from' => $previousScheduledAt,
                    'to' => $appointment->scheduled_at->toDateTimeString(),
                ],
            );

            return $appointment->fresh(['customer', 'visitReason', 'status', 'optometrist']);
        });
    }

    private function createSmsNotification(Appointment $appointment): void
    {
        SmsNotification::query()->create([
            'appointment_id' => $appointment->id,
            'notification_status_id' => NotificationStatus::query()->where('name', 'queued')->value('id'),
            'event' => 'appointment_rescheduled',
            'recipient' => $appointment->customer->phone ?? $appointment->customer->email,
            'message' => "Your appointment has been rescheduled to {$appointment->scheduled_at->toDateTimeString()}.",
        ]);
    }
}
