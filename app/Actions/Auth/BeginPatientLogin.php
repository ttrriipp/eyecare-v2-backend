<?php

namespace App\Actions\Auth;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use App\Enums\OtpPurpose;
use App\Models\PatientAccountContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class BeginPatientLogin
{
    public function __construct(
        protected NormalizeContact $normalize,
        protected CreateContactLookupHash $lookupHash,
        protected IssueOtpChallenge $issueOtp,
        protected IssuePatientDeviceToken $issueToken,
    ) {}

    public function handle(
        string $contactValue,
        string $password,
        ?string $deviceName = null,
        ?string $installationId = null,
    ): array {
        $contact = $this->findVerifiedPhone($contactValue);

        if ($contact === null) {
            Hash::make('dummy'); // timing attack mitigation

            return $this->genericFailure();
        }

        $user = $contact->user;

        if (! Hash::check($password, $user->password)) {
            return $this->genericFailure();
        }

        if ($installationId !== null && $this->hasTrustedInstallation($user, $installationId)) {
            $tokenResult = $this->issueToken->issueForUser($user, $deviceName, $installationId);

            return [
                'step_up_required' => false,
                'token' => $tokenResult['token'],
                'user' => $tokenResult['user'],
            ];
        }

        // Normalize the phone value for the OTP challenge
        $normalizedValue = $this->normalize->phone($contactValue);

        // Issue login step-up OTP
        $otpResult = $this->issueOtp->handle(
            contactType: 'phone',
            contactValue: $normalizedValue,
            purpose: OtpPurpose::LoginStepUp,
            userId: $user->id,
        );

        $challenge = $otpResult['challenge'];

        return [
            'step_up_required' => true,
            'challenge_id' => $challenge->public_id,
            'expires_at' => $challenge->expires_at,
        ];
    }

    protected function findVerifiedPhone(string $contactValue): ?PatientAccountContact
    {
        try {
            $phoneHash = $this->lookupHash->forPhone($contactValue);

            return PatientAccountContact::query()
                ->where('lookup_hash', $phoneHash)
                ->where('type', 'phone')
                ->whereNotNull('verified_at')
                ->first();
        } catch (\InvalidArgumentException) {
        }

        return null;
    }

    protected function hasTrustedInstallation(User $user, string $installationId): bool
    {
        return $user->tokens()
            ->where('installation_id', $installationId)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    protected function genericFailure(): array
    {
        throw ValidationException::withMessages([
            'contact_value' => ['The provided credentials are incorrect.'],
        ]);
    }
}
