<?php

namespace App\Notifications;

use App\Enums\AppointmentRescheduleRequestStatus;
use App\Models\AppointmentRescheduleRequest;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class AppointmentRescheduleRequestStatusChanged extends Notification
{
    public function __construct(private readonly AppointmentRescheduleRequest $request) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $appointment = $this->request->appointment;
        $appointmentNumber = $appointment?->appointment_number ?? '#'.$this->request->appointment_id;
        $originalScheduledAt = $this->request->current_scheduled_at?->format('M d, Y g:i A')
            ?? 'the original appointment time';

        [$title, $body, $color] = match ($this->request->status) {
            AppointmentRescheduleRequestStatus::Rejected => [
                'Appointment Reschedule Request Rejected',
                sprintf(
                    'Your reschedule request for appointment %s was not approved. Your appointment remains scheduled for %s. Reason: %s.',
                    $appointmentNumber,
                    $originalScheduledAt,
                    $this->request->rejection_reason,
                ),
                'danger',
            ],
            AppointmentRescheduleRequestStatus::Expired => [
                'Appointment Reschedule Request Expired',
                sprintf(
                    'Your reschedule request for appointment %s expired. Your appointment remains scheduled for %s.',
                    $appointmentNumber,
                    $originalScheduledAt,
                ),
                'warning',
            ],
            default => [
                'Appointment Reschedule Request Updated',
                sprintf(
                    'Your reschedule request for appointment %s was updated.',
                    $appointmentNumber,
                ),
                'info',
            ],
        };

        return FilamentNotification::make()
            ->icon('heroicon-o-calendar-days')
            ->iconColor($color)
            ->title($title)
            ->body($body)
            ->getDatabaseMessage();
    }
}
