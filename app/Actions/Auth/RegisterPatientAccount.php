<?php

namespace App\Actions\Auth;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\PatientAccountContact;
use App\Models\PatientInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RegisterPatientAccount
{
    public function __construct(
        protected NormalizeContact $normalize,
        protected CreateContactLookupHash $lookupHash,
        protected IssuePatientDeviceToken $issueToken,
    ) {}

    /**
     * Step 1: Verify OTP and return a registration token (short-lived proof).
     */
    public function verifyRegistration(string $challengeId, string $code): array
    {
        $challenge = OtpChallenge::where('public_id', $challengeId)->first();

        if ($challenge === null) {
            throw ValidationException::withMessages([
                'challenge_id' => ['The provided challenge is invalid.'],
            ]);
        }

        if ($challenge->purpose !== OtpPurpose::Registration) {
            throw ValidationException::withMessages([
                'challenge_id' => ['The provided challenge is invalid.'],
            ]);
        }

        if ($challenge->channel !== 'phone') {
            throw ValidationException::withMessages([
                'challenge_id' => ['Registration requires a verified phone number.'],
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

        // Generate a short-lived registration token
        $registrationToken = bin2hex(random_bytes(32));

        // Store the token hash on the challenge for verification in step 2
        $challenge->update([
            'consumed_at' => now(),
            'delivery_status' => 'registration_token_issued',
        ]);

        // Create a temporary registration proof (expires in 30 minutes)
        $proof = OtpChallenge::create([
            'public_id' => $registrationToken,
            'user_id' => null,
            'purpose' => OtpPurpose::Registration,
            'channel' => $challenge->channel,
            'encrypted_destination' => $challenge->encrypted_destination,
            'destination_hash' => $challenge->destination_hash,
            'code_digest' => Hash::make('proof'), // Not used for verification
            'attempts' => 0,
            'max_attempts' => 1,
            'expires_at' => now()->addMinutes(30),
            'last_sent_at' => now(),
            'consumed_at' => null,
            'invalidated_at' => null,
            'delivery_status' => 'proof',
        ]);

        return [
            'registration_token' => $registrationToken,
            'expires_at' => $proof->expires_at,
            'contact_type' => $challenge->channel,
        ];
    }

    /**
     * Step 2: Complete registration using the proof token.
     */
    public function handle(array $data): array
    {
        // Validate policy versions against server config
        $this->validatePolicies($data);

        // Find and validate the registration proof
        $proof = OtpChallenge::where('public_id', $data['registration_token'])
            ->where('purpose', OtpPurpose::Registration)
            ->where('delivery_status', 'proof')
            ->first();

        if ($proof === null) {
            throw ValidationException::withMessages([
                'registration_token' => ['The registration token is invalid.'],
            ]);
        }

        if ($proof->isExpired()) {
            throw ValidationException::withMessages([
                'registration_token' => ['The registration token has expired. Please verify again.'],
            ]);
        }

        if ($proof->isConsumed()) {
            throw ValidationException::withMessages([
                'registration_token' => ['This registration token has already been used.'],
            ]);
        }

        $contactType = $proof->channel;
        $destination = $proof->encrypted_destination;

        if ($contactType !== 'phone') {
            throw ValidationException::withMessages([
                'registration_token' => ['Registration requires a verified phone number.'],
            ]);
        }

        $optionalEmail = null;

        if ($contactType === 'phone' && isset($data['email'])) {
            $optionalEmail = $this->normalize->email($data['email']);
        }

        $optionalEmailHash = $optionalEmail === null
            ? null
            : $this->lookupHash->forEmail($optionalEmail);

        return DB::transaction(function () use ($data, $contactType, $destination, $proof, $optionalEmail, $optionalEmailHash) {
            if ($this->contactIsAlreadyOwned($contactType, $destination, $proof->destination_hash)) {
                return [
                    'contact_already_owned' => true,
                    'contact_type' => $contactType,
                    'is_new' => false,
                ];
            }

            if ($optionalEmail !== null && $optionalEmailHash !== null
                && $this->contactIsAlreadyOwned('email', $optionalEmail, $optionalEmailHash)) {
                return [
                    'contact_already_owned' => true,
                    'contact_type' => 'email',
                    'is_new' => false,
                ];
            }

            $role = Role::where('name', Role::Patient)->firstOrFail();

            $middleName = $data['middle_name'] ?? null;

            $user = User::create([
                'first_name' => $data['first_name'],
                'middle_name' => $middleName,
                'last_name' => $data['last_name'],
                'date_of_birth' => $data['date_of_birth'],
                'email' => $optionalEmail,
                'phone' => $contactType === 'phone' ? $destination : null,
                'password' => Hash::make($data['password']),
                'role_id' => $role->id,
                // Store authoritative policy metadata from server config
                'privacy_notice_version' => $data['privacy_policy_version'],
                'privacy_acknowledged_at' => now(),
            ]);

            $user->roles()->sync([$role->id]);

            PatientAccountContact::create([
                'user_id' => $user->id,
                'type' => $contactType,
                'encrypted_value' => $destination,
                'lookup_hash' => $proof->destination_hash,
                'verified_at' => now(),
                'is_primary' => true,
            ]);

            if ($optionalEmail !== null && $optionalEmailHash !== null) {
                PatientAccountContact::create([
                    'user_id' => $user->id,
                    'type' => 'email',
                    'encrypted_value' => $optionalEmail,
                    'lookup_hash' => $optionalEmailHash,
                    'verified_at' => null,
                    'is_primary' => false,
                ]);
            }

            // Consume the proof
            $proof->update(['consumed_at' => now()]);

            // Handle invitation code if provided
            if (! empty($data['invitation_code'])) {
                $this->acceptInvitation($data['invitation_code'], $user);
            }

            $tokenResult = $this->issueToken->issueForUser(
                $user,
                $data['device_name'] ?? null,
                $data['installation_id'] ?? null,
            );

            return [
                'token' => $tokenResult['token'],
                'user' => $user,
                'is_new' => true,
                'email_verification_required' => $optionalEmail !== null,
            ];
        });
    }

    protected function contactIsAlreadyOwned(string $contactType, string $destination, string $destinationHash): bool
    {
        if (PatientAccountContact::query()
            ->where('lookup_hash', $destinationHash)
            ->where('type', $contactType)
            ->exists()) {
            return true;
        }

        if ($contactType === 'email') {
            return User::query()->where('email', $destination)->exists();
        }

        return User::query()
            ->whereIn('phone', $this->phoneVariants($destination))
            ->exists();
    }

    /**
     * @return list<string>
     */
    protected function phoneVariants(string $phone): array
    {
        $digits = ltrim($phone, '+');

        return array_values(array_unique([
            $phone,
            $digits,
            '0'.substr($digits, 2),
            substr($digits, 2),
        ]));
    }

    protected function acceptInvitation(string $code, User $user): void
    {
        $invitation = PatientInvitation::where('invitation_code', $code)->first();

        if ($invitation === null || ! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation_code' => ['The invitation code is invalid or has expired.'],
            ]);
        }

        $userContact = $user->contacts()
            ->where('type', $invitation->channel)
            ->where('verified_at', '!=', null)
            ->first();

        if ($userContact === null || $userContact->lookup_hash !== $invitation->destination_hash) {
            throw ValidationException::withMessages([
                'invitation_code' => ['The invitation does not match your registered contact.'],
            ]);
        }

        $patient = $invitation->patient;
        if ($patient->user_id !== null) {
            throw ValidationException::withMessages([
                'invitation_code' => ['The patient record is already linked to another account.'],
            ]);
        }

        $patient->update(['user_id' => $user->id]);
        $invitation->accept($user);
    }

    protected function validatePolicies(array $data): void
    {
        $errors = [];

        $serverPrivacyVersion = config('app.privacy_policy_version');
        if (! empty($serverPrivacyVersion) && ($data['privacy_policy_version'] ?? '') !== $serverPrivacyVersion) {
            $errors['privacy_policy_version'] = ['The accepted privacy policy version does not match the current version.'];
        }

        $serverTermsVersion = config('app.terms_version');
        if (! empty($serverTermsVersion) && ($data['terms_version'] ?? '') !== $serverTermsVersion) {
            $errors['terms_version'] = ['The accepted terms version does not match the current version.'];
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
