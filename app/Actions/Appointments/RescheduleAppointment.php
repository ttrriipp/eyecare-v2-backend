<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Notifications\NotifyAdminUsers;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AppointmentReschedule;
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
        private readonly NotifyAdminUsers $notifyAdminUsers,
    ) {}

    public function handle(
        Appointment $appointment,
        CarbonInterface $scheduledAt,
        bool $customerInitiated,
        ?string $rescheduleReason = null,
        ?string $reasonCategory = null,
    ): Appointment {
        if (! in_array($appointment->status->name, ['scheduled'], true)) {
            throw ValidationException::withMessages([
                'appointment' => ['This appointment cannot be rescheduled.'],
            ]);
        }

        $rescheduleReason = filled($rescheduleReason) ? trim($rescheduleReason) : null;

        // Staff rescheduling requires a reason category
        if (! $customerInitiated && blank($reasonCategory)) {
            throw ValidationException::withMessages([
                'reason_category' => ['A reason category is required for clinic-initiated rescheduling.'],
            ]);
        }

        // 'other' requires details
        if ($reasonCategory === 'other' && blank($rescheduleReason)) {
            throw ValidationException::withMessages([
                'reschedule_reason' => ['Please provide details when selecting "other" as the reason.'],
            ]);
        }

        $appointment->loadMissing(['appointmentType', 'optometrist', 'patient']);

        $previousScheduledAtForNotification = $appointment->scheduled_at->format('M d, Y g:i A');

        $rescheduledAppointment = DB::transaction(function () use ($appointment, $scheduledAt, $customerInitiated, $rescheduleReason, $reasonCategory): Appointment {
            $this->lockScheduleDates($appointment, $scheduledAt);

            try {
                $this->scheduleAppointment->handle(
                    scheduledAt: $scheduledAt,
                    durationMinutes: $appointment->duration_minutes,
                    optometrist: $appointment->optometrist,
                    ignoreAppointment: $appointment,
                    enforceGrid: true,
                );
            } catch (ValidationException $exception) {
                $this->throwStructuredSlotUnavailable($exception, $appointment, $scheduledAt);
            }

            $previousScheduledAt = $appointment->scheduled_at->toDateTimeString();
            $attributes = ['scheduled_at' => $scheduledAt];

            if ($customerInitiated) {
                $attributes['appointment_status_id'] = AppointmentStatus::query()
                    ->where('name', 'scheduled')
                    ->value('id');
            }

            $appointment->update($attributes);

            // Create immutable reschedule history
            AppointmentReschedule::query()->create([
                'appointment_id' => $appointment->id,
                'previous_scheduled_at' => $previousScheduledAt,
                'new_scheduled_at' => $appointment->fresh()->scheduled_at,
                'initiated_by' => $customerInitiated ? 'patient' : 'clinic',
                'actor_id' => auth()->id(),
                'reason_category' => $reasonCategory,
                'reason_details' => $rescheduleReason,
                'rescheduled_at' => now(),
            ]);

            $appointment->load(['patient', 'appointmentType', 'status', 'optometrist']);

            $this->createSmsNotification($appointment, $rescheduleReason);
            $appointment->patient->account?->notify(new AppointmentRescheduled($appointment));
            $this->createAuditLog->handle(
                subject: $appointment,
                action: AuditEvent::AppointmentRescheduled,
                metadata: array_filter([
                    'from' => $previousScheduledAt,
                    'to' => $appointment->scheduled_at->toDateTimeString(),
                    'reason_category' => $reasonCategory,
                    'reason' => $rescheduleReason,
                ], fn ($value): bool => $value !== null),
                actorId: auth()->id(),
            );

            return $appointment->fresh(['patient', 'appointmentType', 'status', 'optometrist']);
        }, attempts: 3);

        if ($customerInitiated) {
            $this->notifyAdminUsers->appointmentRescheduled(
                $rescheduledAppointment,
                $previousScheduledAtForNotification,
            );
        }

        return $rescheduledAppointment;
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
                'appointment_type_id' => $appointment->appointment_type_id,
                'optometrist_id' => $appointment->optometrist_id,
                'appointment_id' => $appointment->id,
            ],
        ], 422));
    }

    private function createSmsNotification(Appointment $appointment, ?string $rescheduleReason): void
    {
        $message = "Your appointment {$appointment->appointment_number} has been rescheduled to {$appointment->scheduled_at->toDateTimeString()}.";

        if ($rescheduleReason !== null) {
            $message .= " Reason: {$rescheduleReason}.";
        }

        SmsNotification::query()->create([
            'appointment_id' => $appointment->id,
            'notification_status_id' => NotificationStatus::query()->where('name', 'queued')->value('id'),
            'event' => 'appointment_rescheduled',
            'recipient' => $appointment->patient->phone ?? $appointment->patient->contact_email,
            'message' => $message,
        ]);
    }
}
