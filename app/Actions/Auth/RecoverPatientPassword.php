<?php

namespace App\Actions\Auth;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use App\Enums\OtpPurpose;
use App\Models\PatientAccountContact;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RecoverPatientPassword
{
    public function __construct(
        protected NormalizeContact $normalize,
        protected CreateContactLookupHash $lookupHash,
        protected VerifyOtpChallenge $verifyOtp,
    ) {}

    public function handle(
        string $challengeId,
        string $code,
        string $newPassword,
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

        $token = $user->createToken(
            'mobile',
            ['*'],
            now()->addDays(config('patient_accounts.tokens.expiry_days', 30))
        )->plainTextToken;

        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    public function findUserByContact(string $contactValue): ?User
    {
        try {
            $emailHash = $this->lookupHash->forEmail($contactValue);
            $contact = PatientAccountContact::where('lookup_hash', $emailHash)
                ->where('type', 'email')
                ->whereNotNull('verified_at')
                ->first();

            if ($contact !== null) {
                return $contact->user;
            }
        } catch (\InvalidArgumentException) {
        }

        try {
            $phoneHash = $this->lookupHash->forPhone($contactValue);
            $contact = PatientAccountContact::where('lookup_hash', $phoneHash)
                ->where('type', 'phone')
                ->whereNotNull('verified_at')
                ->first();

            if ($contact !== null) {
                return $contact->user;
            }
        } catch (\InvalidArgumentException) {
        }

        return null;
    }
}
