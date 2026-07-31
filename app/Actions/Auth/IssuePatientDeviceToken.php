<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IssuePatientDeviceToken
{
    public function __construct(
        protected VerifyOtpChallenge $verifyOtp,
    ) {}

    public function handle(
        string $challengeId,
        string $code,
        ?string $deviceName = null,
        ?string $installationId = null,
    ): array {
        $challenge = $this->verifyOtp->handle(
            challengeId: $challengeId,
            code: $code,
            expectedPurpose: OtpPurpose::LoginStepUp,
        );

        $user = User::findOrFail($challenge->user_id);

        return DB::transaction(function () use ($user, $deviceName, $installationId) {
            // Revoke existing token for same installation if provided
            if ($installationId !== null) {
                $user->tokens()
                    ->where('name', $deviceName ?? 'mobile')
                    ->each(function ($token) {
                        $token->delete();
                    });
            }

            // Enforce max active tokens
            $maxTokens = config('patient_accounts.tokens.max_active', 5);
            $activeTokens = $user->tokens()->count();

            if ($activeTokens >= $maxTokens) {
                $oldestTokens = $user->tokens()
                    ->orderBy('created_at', 'asc')
                    ->limit($activeTokens - $maxTokens + 1)
                    ->get();

                foreach ($oldestTokens as $token) {
                    $token->delete();
                }
            }

            $token = $user->createToken(
                $deviceName ?? 'mobile',
                ['*'],
                now()->addDays(config('patient_accounts.tokens.expiry_days', 30))
            )->plainTextToken;

            return [
                'token' => $token,
                'user' => $user,
            ];
        });
    }
}
