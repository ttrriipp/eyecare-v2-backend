<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class NewAppointmentBooking extends Notification
{
    public function __construct(private readonly Appointment $appointment) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type' => 'new_appointment_booking',
            'title' => 'New Appointment Booked',
            'body' => "{$this->appointment->customer->name} booked an appointment on {$this->appointment->scheduled_at->format('M d, Y g:i A')}.",
            'action_url' => '/admin/appointments/'.$this->appointment->id.'/edit',
            'related_type' => 'appointment',
            'related_id' => $this->appointment->id,
        ]);
    }
}
