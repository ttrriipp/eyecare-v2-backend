<?php

namespace App\Actions\Auth;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use App\Enums\OtpPurpose;
use App\Models\PatientAccountContact;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class BeginPatientLogin
{
    public function __construct(
        protected NormalizeContact $normalize,
        protected CreateContactLookupHash $lookupHash,
        protected IssueOtpChallenge $issueOtp,
    ) {}

    public function handle(string $contactValue, string $password): array
    {
        $contact = $this->findVerifiedContact($contactValue);

        if ($contact === null) {
            Hash::make('dummy'); // timing attack mitigation

            return $this->genericFailure();
        }

        $user = $contact->user;

        if (! Hash::check($password, $user->password)) {
            return $this->genericFailure();
        }

        // Normalize the contact value for the OTP challenge
        $normalizedValue = $contact->type === 'email'
            ? $this->normalize->email($contactValue)
            : $this->normalize->phone($contactValue);

        // Issue login step-up OTP
        $challenge = $this->issueOtp->handle(
            contactType: $contact->type,
            contactValue: $normalizedValue,
            purpose: OtpPurpose::LoginStepUp,
            userId: $user->id,
        );

        return [
            'step_up_required' => true,
            'challenge_id' => $challenge->public_id,
            'expires_at' => $challenge->expires_at,
        ];
    }

    protected function findVerifiedContact(string $contactValue): ?PatientAccountContact
    {
        // Try email first
        try {
            $emailHash = $this->lookupHash->forEmail($contactValue);
            $contact = PatientAccountContact::where('lookup_hash', $emailHash)
                ->where('type', 'email')
                ->whereNotNull('verified_at')
                ->first();

            if ($contact !== null) {
                return $contact;
            }
        } catch (\InvalidArgumentException) {
        }

        // Try phone
        try {
            $phoneHash = $this->lookupHash->forPhone($contactValue);
            $contact = PatientAccountContact::where('lookup_hash', $phoneHash)
                ->where('type', 'phone')
                ->whereNotNull('verified_at')
                ->first();

            if ($contact !== null) {
                return $contact;
            }
        } catch (\InvalidArgumentException) {
        }

        return null;
    }

    protected function genericFailure(): array
    {
        throw ValidationException::withMessages([
            'contact_value' => ['The provided credentials are incorrect.'],
        ]);
    }
}
