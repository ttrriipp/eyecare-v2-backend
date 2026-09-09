<?php

namespace App\Jobs;

use App\Mail\OtpMail;
use App\Models\OtpChallenge;
use App\Services\SmsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class DeliverOtpChallenge implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $challengeId,
        public string $code,
    ) {}

    public function handle(SmsGateway $smsGateway): void
    {
        $challenge = OtpChallenge::where('public_id', $this->challengeId)->first();

        if ($challenge === null || ! $challenge->isPending()) {
            return;
        }

        $destination = $challenge->encrypted_destination;

        if ($destination === null) {
            $challenge->markFailed();

            return;
        }

        try {
            if ($challenge->channel === 'email') {
                Mail::to($destination)->send(new OtpMail($this->code, $challenge->purpose->value));
            } elseif (app()->environment(['local', 'testing'])) {
                Log::info('SMS OTP delivery (development only)', [
                    'challenge_id' => $challenge->public_id,
                    'masked' => $this->maskPhone($destination),
                    'code' => $this->code,
                ]);
            } elseif ($challenge->channel === 'phone') {
                if (! $smsGateway->isEnabled()) {
                    $challenge->markFailed();
                    Log::warning('SMS OTP delivery skipped (provider disabled)', [
                        'challenge_id' => $challenge->public_id,
                        'masked' => $this->maskPhone($destination),
                    ]);

                    return;
                }

                if (! $smsGateway->send($destination, $this->smsMessage())) {
                    throw new RuntimeException('SMS provider returned a failure response.');
                }
            } else {
                $challenge->markFailed();
                Log::warning('OTP delivery skipped (unsupported channel)', [
                    'challenge_id' => $challenge->public_id,
                    'channel' => $challenge->channel,
                ]);

                return;
            }

            $challenge->markSent();
        } catch (Throwable $e) {
            $challenge->markFailed();
            Log::error('OTP delivery failed', [
                'challenge_id' => $challenge->public_id,
                'masked' => $this->maskPhone($destination),
                'exception' => $e::class,
            ]);
            throw $e;
        }
    }

    protected function smsMessage(): string
    {
        return sprintf(
            'EyeCare verification code: %s. Expires in %d minutes. If you did not request this, ignore this message.',
            $this->code,
            (int) config('patient_accounts.otp.lifetime_minutes', 10),
        );
    }

    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 4) {
            return '***';
        }

        return substr($phone, 0, 3).'***'.substr($phone, -4);
    }
}
