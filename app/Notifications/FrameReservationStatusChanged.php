<?php

namespace App\Notifications;

use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FrameReservationStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly FrameReservation $reservation,
        private readonly ReservationStatus $previousStatus,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = $this->reservation->status;
        $appointment = $this->reservation->appointment;

        $message = (new MailMessage)
            ->subject($this->getSubject())
            ->greeting("Hello {$notifiable->full_name},");

        match ($status) {
            ReservationStatus::Prepared => $message
                ->line('Your selected frames have been prepared and are ready for your visit.')
                ->line('The frames will be held until the end of your appointment day.'),
            ReservationStatus::Released => $message
                ->line('Your frame reservation has been released.')
                ->line('The selected frames are no longer held for your appointment.'),
            ReservationStatus::Converted => $message
                ->line('Your frame selection has been confirmed and added to your optical order.')
                ->line('The frames will be prepared for dispensing.'),
            default => $message->line('Your frame reservation status has been updated.'),
        };

        if ($appointment !== null) {
            $message->line("Appointment: {$appointment->appointment_number}")
                ->line("Scheduled: {$appointment->scheduled_at->format('M j, Y g:i A')}");
        }

        return $message
            ->action('View Appointment', route('filament.admin.resources.appointments.edit', ['record' => $appointment?->id]))
            ->line('Thank you for choosing Padilla Optical Clinic.');
    }

    private function getSubject(): string
    {
        return match ($this->reservation->status) {
            ReservationStatus::Prepared => 'Your Frames Are Ready for Your Visit',
            ReservationStatus::Released => 'Frame Reservation Released',
            ReservationStatus::Converted => 'Frame Selection Confirmed',
            default => 'Frame Reservation Update',
        };
    }
}
