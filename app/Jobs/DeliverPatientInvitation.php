<?php

namespace App\Jobs;

use App\Mail\PatientInvitationMail;
use App\Models\PatientInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DeliverPatientInvitation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $invitationId,
    ) {}

    public function handle(): void
    {
        $invitation = PatientInvitation::find($this->invitationId);

        if ($invitation === null || ! $invitation->isPending()) {
            return;
        }

        $destination = $invitation->encrypted_destination;

        if (empty($destination)) {
            return;
        }

        try {
            if ($invitation->channel === 'email') {
                $this->sendEmail($invitation, $destination);
            } else {
                $this->sendSms($invitation, $destination);
            }

            Log::info('Invitation delivery dispatched', [
                'invitation_id' => $invitation->id,
                'channel' => $invitation->channel,
                'masked' => $this->mask($destination, $invitation->channel),
            ]);
        } catch (\Throwable $e) {
            Log::error('Invitation delivery failed', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function sendEmail(PatientInvitation $invitation, string $email): void
    {
        // Send invitation email using Mailable
        Mail::to($email)->queue(new PatientInvitationMail($invitation));
    }

    protected function sendSms(PatientInvitation $invitation, string $phone): void
    {
        // SMS delivery would go through the existing SMS provider boundary
        Log::info('SMS invitation delivery not yet implemented', [
            'invitation_id' => $invitation->id,
            'masked_phone' => $this->mask($phone, 'phone'),
        ]);
    }

    protected function mask(string $value, string $channel): string
    {
        if ($channel === 'email') {
            $parts = explode('@', $value);
            if (count($parts) === 2) {
                return substr($parts[0], 0, 1).'***@'.$parts[1];
            }
        }

        if (strlen($value) >= 4) {
            return substr($value, 0, 3).'***'.substr($value, -4);
        }

        return '***';
    }
}
