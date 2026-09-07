<?php

namespace App\Actions\Notifications;

use App\Models\AppointmentRescheduleRequest;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Notifications\AppointmentRescheduled;
use App\Notifications\AppointmentRescheduleRequestStatusChanged;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotifyPatientAppointmentRescheduleRequest
{
    public function approved(AppointmentRescheduleRequest $request): void
    {
        $this->afterCommit($request, 'approved');
    }

    public function rejected(AppointmentRescheduleRequest $request): void
    {
        $this->afterCommit($request, 'rejected');
    }

    public function expired(AppointmentRescheduleRequest $request): void
    {
        $this->afterCommit($request, 'expired');
    }

    private function afterCommit(AppointmentRescheduleRequest $request, string $outcome): void
    {
        DB::afterCommit(function () use ($request, $outcome): void {
            try {
                $request = $request->fresh([
                    'appointment',
                    'patient.account',
                    'user',
                ]);

                if ($request === null) {
                    return;
                }

                $appointment = $request->appointment;

                if ($appointment === null) {
                    return;
                }

                $account = $request->user ?? $request->patient?->account;

                if ($account !== null) {
                    $account->notify(match ($outcome) {
                        'approved' => new AppointmentRescheduled($appointment),
                        default => new AppointmentRescheduleRequestStatusChanged($request),
                    });
                }

                if ($outcome !== 'expired') {
                    $this->createSmsNotification($request, $outcome);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function createSmsNotification(AppointmentRescheduleRequest $request, string $outcome): void
    {
        $appointment = $request->appointment;
        $recipient = filled($request->patient?->phone)
            ? $request->patient->phone
            : $request->patient?->contact_email;

        if ($appointment === null || blank($recipient)) {
            return;
        }

        $message = match ($outcome) {
            'approved' => sprintf(
                'Your appointment %s has been rescheduled to %s.',
                $appointment->appointment_number,
                $appointment->scheduled_at->toDateTimeString(),
            ),
            'rejected' => sprintf(
                'Your reschedule request for appointment %s was not approved. Your appointment remains scheduled for %s. Reason: %s.',
                $appointment->appointment_number,
                $request->current_scheduled_at?->toDateTimeString() ?? 'the original appointment time',
                $request->rejection_reason,
            ),
            default => null,
        };

        if ($message === null) {
            return;
        }

        SmsNotification::query()->create([
            'appointment_id' => $appointment->id,
            'notification_status_id' => NotificationStatus::query()->where('name', 'queued')->value('id'),
            'event' => $outcome === 'approved'
                ? 'appointment_rescheduled'
                : 'appointment_reschedule_request_rejected',
            'recipient' => $recipient,
            'message' => $message,
        ]);
    }
}
