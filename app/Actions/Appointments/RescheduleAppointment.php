<?php

namespace App\Actions\Appointments;

use App\Actions\Notifications\NotifyAdminUsers;
use App\Models\Appointment;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Notifications\AppointmentRescheduled;
use Carbon\CarbonInterface;

class RescheduleAppointment
{
    public function __construct(
        private readonly CommitAppointmentReschedule $commitAppointmentReschedule,
        private readonly NotifyAdminUsers $notifyAdminUsers,
    ) {}

    public function handle(
        Appointment $appointment,
        CarbonInterface $scheduledAt,
        bool $customerInitiated,
        ?string $rescheduleReason = null,
        ?string $reasonCategory = null,
    ): Appointment {
        $rescheduleReason = filled($rescheduleReason) ? trim($rescheduleReason) : null;

        $history = $this->commitAppointmentReschedule->handle(
            appointment: $appointment,
            scheduledAt: $scheduledAt,
            initiator: $customerInitiated ? 'patient' : 'clinic',
            actor: auth()->user(),
            reasonCategory: $reasonCategory,
            reasonDetails: $rescheduleReason,
        );

        /** @var Appointment $rescheduledAppointment */
        $rescheduledAppointment = $history->appointment;

        $this->createSmsNotification($rescheduledAppointment, $rescheduleReason);
        $rescheduledAppointment->patient->account?->notify(new AppointmentRescheduled($rescheduledAppointment));

        if ($customerInitiated) {
            $this->notifyAdminUsers->appointmentRescheduled(
                $rescheduledAppointment,
                $history->previous_scheduled_at->format('M d, Y g:i A'),
            );
        }

        return $rescheduledAppointment;
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
