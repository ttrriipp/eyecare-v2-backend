<?php

namespace App\Jobs;

use App\Models\OtpChallenge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class DeliverOtpChallenge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $challengeId,
    ) {}

    public function handle(): void
    {
        $challenge = OtpChallenge::where('public_id', $this->challengeId)->first();

        if ($challenge === null || $challenge->isConsumed() || $challenge->isInvalidated()) {
            return;
        }

        $destination = $challenge->encrypted_destination;

        if ($destination === null) {
            $challenge->markFailed();

            return;
        }

        try {
            if ($challenge->channel === 'email') {
                // For now, log the OTP delivery. In production, use Notification::route()
                Log::info('OTP delivery requested', [
                    'challenge_id' => $challenge->public_id,
                    'channel' => 'email',
                    'masked' => $this->maskEmail($destination),
                ]);
            } else {
                Log::info('OTP delivery requested', [
                    'challenge_id' => $challenge->public_id,
                    'channel' => 'phone',
                    'masked' => $this->maskPhone($destination),
                ]);
            }

            $challenge->markSent();
        } catch (\Throwable $e) {
            $challenge->markFailed();
            throw $e;
        }
    }

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }

        $name = $parts[0];
        $domain = $parts[1];

        return substr($name, 0, 1).'***@'.$domain;
    }

    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 4) {
            return '***';
        }

        return substr($phone, 0, 3).'***'.substr($phone, -4);
    }
}
