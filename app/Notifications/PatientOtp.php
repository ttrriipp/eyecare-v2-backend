<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PatientOtp extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $code,
        public string $purpose,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your EyeCare Verification Code')
            ->line('Your verification code is: **'.$this->code.'**')
            ->line('This code will expire in 10 minutes.')
            ->line('If you did not request this code, please ignore this message.');
    }
}
