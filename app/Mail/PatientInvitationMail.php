<?php

namespace App\Mail;

use App\Models\PatientInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PatientInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PatientInvitation $invitation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'re invited to connect with Eyecare',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.patient-invitation',
            with: [
                'patientName' => $this->invitation->patient?->full_name ?? 'Patient',
                'invitationCode' => $this->invitation->public_id,
                'channel' => $this->invitation->channel,
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
