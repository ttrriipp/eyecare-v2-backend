<?php

namespace App\Notifications;

use App\Models\Appointment;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class AppointmentStatusChanged extends Notification
{
    public function __construct(private readonly Appointment $appointment) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $status = $this->appointment->status->name;

        return FilamentNotification::make()
            ->icon('heroicon-o-calendar-days')
            ->iconColor(match ($status) {
                'confirmed' => 'success',
                'cancelled' => 'danger',
                'rescheduled' => 'warning',
                'completed' => 'success',
                default => 'info',
            })
            ->title('Appointment '.ucfirst($status))
            ->body("Your appointment scheduled on {$this->appointment->scheduled_at->format('M d, Y g:i A')} has been {$status}.")
            ->getDatabaseMessage();
    }
}
