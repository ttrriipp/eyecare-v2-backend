<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VerifyStepUpOtp
{
    public function handle(
        string $challengeId,
        string $code,
        User $user,
    ): string {
        $challenge = OtpChallenge::where('public_id', $challengeId)
            ->where('user_id', $user->id)
            ->where('purpose', OtpPurpose::SensitiveChange)
            ->first();

        if ($challenge === null) {
            throw ValidationException::withMessages([
                'step_up_token' => ['The provided challenge is invalid.'],
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

        if (! $challenge->hasAttemptsRemaining()) {
            throw ValidationException::withMessages([
                'code' => ['Too many verification attempts.'],
            ]);
        }

        if (! Hash::check($code, $challenge->code_digest)) {
            $challenge->incrementAttempts();
            throw ValidationException::withMessages([
                'code' => ['The provided verification code is incorrect.'],
            ]);
        }

        // Generate a short-lived step-up token
        $stepUpToken = bin2hex(random_bytes(32));

        // Store the token hash for validation
        $challenge->update([
            'consumed_at' => now(),
            'delivery_status' => 'step_up_token_issued:'.Hash::make($stepUpToken),
        ]);

        return $stepUpToken;
    }

    /**
     * Validate a step-up token was recently issued for the user.
     */
    public function validateStepUpToken(string $stepUpToken, User $user): bool
    {
        // Find recently consumed sensitive_change challenges for this user
        $challenge = OtpChallenge::where('user_id', $user->id)
            ->where('purpose', OtpPurpose::SensitiveChange)
            ->whereNotNull('consumed_at')
            ->where('consumed_at', '>', now()->subMinutes(15))
            ->where('delivery_status', 'like', 'step_up_token_issued:%')
            ->first();

        if ($challenge === null) {
            return false;
        }

        $storedHash = substr($challenge->delivery_status, strlen('step_up_token_issued:'));

        return Hash::check($stepUpToken, $storedHash);
    }
}
