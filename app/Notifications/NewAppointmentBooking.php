<?php

namespace App\Notifications;

use App\Models\Appointment;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class NewAppointmentBooking extends Notification
{
    public function __construct(private readonly Appointment $appointment) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->icon('heroicon-o-calendar')
            ->iconColor('success')
            ->title('New Appointment Booked')
            ->body("{$this->appointment->customer->name} booked an appointment on {$this->appointment->scheduled_at->format('M d, Y g:i A')}.")
            ->getDatabaseMessage();
    }
}
