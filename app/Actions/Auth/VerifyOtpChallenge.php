<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class VerifyOtpChallenge
{
    public function handle(
        string $challengeId,
        string $code,
        OtpPurpose $expectedPurpose,
        ?string $ip = null,
        ?int $expectedUserId = null,
    ): OtpChallenge {
        $query = OtpChallenge::query()->where('public_id', $challengeId);

        if ($expectedUserId !== null) {
            $query->where('user_id', $expectedUserId);
        }

        $challenge = $query->first();

        if ($challenge === null) {
            throw ValidationException::withMessages([
                'challenge_id' => ['The provided challenge is invalid.'],
            ]);
        }

        if ($challenge->purpose !== $expectedPurpose) {
            throw ValidationException::withMessages([
                'challenge_id' => ['The provided challenge is invalid.'],
            ]);
        }

        if ($challenge->isExpired()) {
            throw ValidationException::withMessages([
                'code' => ['The verification code has expired.'],
            ]);
        }

        if ($challenge->isConsumed()) {
            throw ValidationException::withMessages([
                'code' => ['This verification code has already been used.'],
            ]);
        }

        if ($challenge->isInvalidated()) {
            throw ValidationException::withMessages([
                'code' => ['This verification code is no longer valid.'],
            ]);
        }

        // Check verification rate limits
        $this->checkVerificationLimits($challenge, $ip);

        if (! Hash::check($code, $challenge->code_digest)) {
            $challenge->incrementAttempts();

            throw ValidationException::withMessages([
                'code' => ['The provided verification code is incorrect.'],
            ]);
        }

        $challenge->consume();

        return $challenge;
    }

    protected function checkVerificationLimits(OtpChallenge $challenge, ?string $ip): void
    {
        $destinationKey = 'otp_verify:'.$challenge->destination_hash;
        $ipKey = 'otp_verify_ip:'.($ip ?? 'unknown');

        $destinationLimit = config('patient_accounts.otp.verification_limit_per_destination_per_window', 10);
        $ipLimit = config('patient_accounts.otp.verification_limit_per_ip_per_window', 20);
        $windowMinutes = config('patient_accounts.otp.window_minutes', 15);

        if (RateLimiter::tooManyAttempts($destinationKey, $destinationLimit)) {
            throw ValidationException::withMessages([
                'code' => ['Too many verification attempts. Please try again later.'],
            ]);
        }

        if ($ip !== null && RateLimiter::tooManyAttempts($ipKey, $ipLimit)) {
            throw ValidationException::withMessages([
                'code' => ['Too many verification attempts. Please try again later.'],
            ]);
        }

        RateLimiter::hit($destinationKey, $windowMinutes * 60);
        if ($ip !== null) {
            RateLimiter::hit($ipKey, $windowMinutes * 60);
        }
    }
}
