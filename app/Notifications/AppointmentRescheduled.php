<?php

namespace App\Notifications;

use App\Models\Appointment;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class AppointmentRescheduled extends Notification
{
    public function __construct(private readonly Appointment $appointment) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->icon('heroicon-o-calendar-days')
            ->iconColor('warning')
            ->title('Appointment Rescheduled')
            ->body("Your appointment is now scheduled for {$this->appointment->scheduled_at->format('M d, Y g:i A')}.")
            ->getDatabaseMessage();
    }
}
