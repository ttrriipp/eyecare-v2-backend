<?php

namespace App\Jobs;

use App\Enums\PatientInvitationStatus;
use App\Mail\PatientInvitationMail;
use App\Models\PatientInvitation;
use App\Services\SmsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class DeliverPatientInvitation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $invitationId,
    ) {}

    public function handle(SmsGateway $smsGateway): void
    {
        $invitation = PatientInvitation::find($this->invitationId);

        if ($invitation === null || ! $invitation->isPending()) {
            return;
        }

        $destination = $invitation->encrypted_destination;

        if (empty($destination)) {
            $invitation->markFailed();
            Log::warning('Invitation delivery skipped (missing destination)', [
                'invitation_id' => $invitation->id,
                'channel' => $invitation->channel,
            ]);

            return;
        }

        try {
            if ($invitation->channel === 'email') {
                $this->sendEmail($invitation, $destination);
            } elseif ($invitation->channel === 'phone') {
                if (! $this->sendSms($invitation, $destination, $smsGateway)) {
                    return;
                }
            } else {
                $invitation->markFailed();
                Log::warning('Invitation delivery skipped (unsupported channel)', [
                    'invitation_id' => $invitation->id,
                    'channel' => $invitation->channel,
                ]);

                return;
            }

            $invitation->update([
                'sent_at' => now(),
                'failed_at' => null,
            ]);

            Log::info('Invitation delivery dispatched', [
                'invitation_id' => $invitation->id,
                'channel' => $invitation->channel,
                'masked' => $this->mask($destination, $invitation->channel),
            ]);
        } catch (Throwable $e) {
            $invitation->recordDeliveryAttemptFailure();
            Log::error('Invitation delivery failed', [
                'invitation_id' => $invitation->id,
                'channel' => $invitation->channel,
                'exception' => $e::class,
            ]);
            throw $e;
        }
    }

    protected function sendEmail(PatientInvitation $invitation, string $email): void
    {
        // Send invitation email using Mailable
        Mail::to($email)->queue(new PatientInvitationMail($invitation));
    }

    protected function sendSms(PatientInvitation $invitation, string $phone, SmsGateway $smsGateway): bool
    {
        if (app()->environment(['local', 'testing'])) {
            Log::info('SMS invitation delivery (development only)', [
                'invitation_id' => $invitation->id,
                'masked_phone' => $this->mask($phone, 'phone'),
                'invitation_code' => $invitation->invitation_code,
            ]);

            return true;
        }

        if (! $smsGateway->isEnabled()) {
            $invitation->markFailed();
            Log::warning('SMS invitation delivery skipped (provider disabled)', [
                'invitation_id' => $invitation->id,
                'masked_phone' => $this->mask($phone, 'phone'),
            ]);

            return false;
        }

        if (! $smsGateway->send($phone, $this->smsMessage($invitation))) {
            throw new RuntimeException('SMS provider returned a failure response.');
        }

        return true;
    }

    public function failed(?Throwable $exception): void
    {
        $invitation = PatientInvitation::find($this->invitationId);

        if ($invitation === null || $invitation->status !== PatientInvitationStatus::Pending) {
            return;
        }

        $invitation->markFailed();
    }

    protected function smsMessage(PatientInvitation $invitation): string
    {
        return sprintf(
            'EyeCare invitation code: %s. Enter it in the EyeCare app to connect your account. This code expires in %d days.',
            $invitation->invitation_code,
            (int) config('patient_accounts.invitations.lifetime_days', 7),
        );
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
