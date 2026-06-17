<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class AppointmentStatusChanged extends Notification
{
    public function __construct(private readonly Appointment $appointment) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $status = $this->appointment->status->name;

        return new DatabaseMessage([
            'type' => 'appointment_status_changed',
            'title' => 'Appointment '.ucfirst($status),
            'body' => "Your appointment scheduled on {$this->appointment->scheduled_at->format('M d, Y g:i A')} has been {$status}.",
            'action_url' => null,
            'related_type' => 'appointment',
            'related_id' => $this->appointment->id,
        ]);
    }
}
