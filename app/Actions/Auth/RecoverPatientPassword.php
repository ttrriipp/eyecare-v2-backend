<?php

namespace App\Actions\Auth;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Enums\OtpPurpose;
use App\Models\PatientAccountContact;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RecoverPatientPassword
{
    public function __construct(
        protected CreateContactLookupHash $lookupHash,
        protected VerifyOtpChallenge $verifyOtp,
        protected IssuePatientDeviceToken $issueToken,
    ) {}

    public function handle(
        string $challengeId,
        string $code,
        string $newPassword,
        ?string $deviceName = null,
        ?string $installationId = null,
    ): array {
        $challenge = $this->verifyOtp->handle(
            challengeId: $challengeId,
            code: $code,
            expectedPurpose: OtpPurpose::PasswordRecovery,
        );

        $user = User::findOrFail($challenge->user_id);

        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        // Revoke all other patient tokens
        $user->tokens()->delete();

        $tokenResult = $this->issueToken->issueForUser($user, $deviceName, $installationId);

        return [
            'token' => $tokenResult['token'],
            'user' => $user,
        ];
    }

    public function findUserByContact(string $contactValue): ?User
    {
        try {
            $phoneHash = $this->lookupHash->forPhone($contactValue);
            $contact = PatientAccountContact::where('lookup_hash', $phoneHash)
                ->where('type', 'phone')
                ->whereNotNull('verified_at')
                ->first();

            return $contact?->user;
        } catch (\InvalidArgumentException) {
        }

        return null;
    }
}
