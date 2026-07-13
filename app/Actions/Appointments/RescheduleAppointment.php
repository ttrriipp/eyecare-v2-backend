<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Notifications\AppointmentRescheduled;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleAppointment
{
    public function __construct(
        private readonly ScheduleAppointment $scheduleAppointment,
        private readonly LockAppointmentScheduleDate $lockAppointmentScheduleDate,
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

        return DB::transaction(function () use ($appointment, $scheduledAt, $customerInitiated): Appointment {
            $this->lockScheduleDates($appointment, $scheduledAt);

            try {
                $this->scheduleAppointment->handle(
                    scheduledAt: $scheduledAt,
                    visitReason: $appointment->visitReason,
                    optometrist: $appointment->optometrist,
                    ignoreAppointment: $appointment,
                    enforceGrid: $customerInitiated,
                );
            } catch (ValidationException $exception) {
                $this->throwStructuredSlotUnavailable($exception, $appointment, $scheduledAt);
            }

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
        }, attempts: 3);
    }

    private function lockScheduleDates(Appointment $appointment, CarbonInterface $scheduledAt): void
    {
        $dates = [
            $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'))->toDateString(),
            $scheduledAt->copy()->setTimezone(config('app.timezone'))->toDateString(),
        ];

        sort($dates);

        foreach (array_unique($dates) as $date) {
            $this->lockAppointmentScheduleDate->handle($date);
        }
    }

    private function throwStructuredSlotUnavailable(
        ValidationException $exception,
        Appointment $appointment,
        CarbonInterface $scheduledAt,
    ): never {
        $scheduledAtErrors = $exception->errors()['scheduled_at'] ?? [];

        if (! in_array('This time slot is not available. Please choose another time.', $scheduledAtErrors, true)) {
            throw $exception;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This time slot is no longer available. Please choose another time.',
            'code' => 'SLOT_UNAVAILABLE',
            'errors' => [
                'scheduled_at' => [
                    'This time slot is no longer available. Please choose another time.',
                ],
            ],
            'availability' => [
                'date' => $scheduledAt->copy()->setTimezone(config('app.timezone'))->toDateString(),
                'visit_reason_id' => $appointment->visit_reason_id,
                'optometrist_id' => $appointment->optometrist_id,
                'appointment_id' => $appointment->id,
            ],
        ], 422));
    }

    private function createSmsNotification(Appointment $appointment): void
    {
        SmsNotification::query()->create([
            'appointment_id' => $appointment->id,
            'notification_status_id' => NotificationStatus::query()->where('name', 'queued')->value('id'),
            'event' => 'appointment_rescheduled',
            'recipient' => $appointment->customer->phone ?? $appointment->customer->email,
            'message' => "Your appointment {$appointment->appointment_number} has been rescheduled to {$appointment->scheduled_at->toDateTimeString()}.",
        ]);
    }
}
