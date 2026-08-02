<?php

namespace App\Jobs;

use App\Mail\OtpMail;
use App\Models\OtpChallenge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DeliverOtpChallenge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $challengeId,
        public string $code,
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
                Mail::to($destination)->send(new OtpMail($this->code, $challenge->purpose->value));
            } elseif (app()->environment(['local', 'testing'])) {
                Log::info('SMS OTP delivery (development only)', [
                    'challenge_id' => $challenge->public_id,
                    'masked' => $this->maskPhone($destination),
                    'code' => $this->code,
                ]);
            } else {
                Log::info('SMS OTP delivery not yet implemented', [
                    'challenge_id' => $challenge->public_id,
                    'masked' => $this->maskPhone($destination),
                ]);
            }

            $challenge->markSent();
        } catch (\Throwable $e) {
            $challenge->markFailed();
            Log::error('OTP delivery failed', [
                'challenge_id' => $challenge->public_id,
                'error' => $e->getMessage(),
            ]);
            // Don't re-throw — allow registration to complete even if delivery fails
        }
    }

    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 4) {
            return '***';
        }

        return substr($phone, 0, 3).'***'.substr($phone, -4);
    }
}
