<?php

namespace App\Actions\Auth;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use App\Enums\OtpPurpose;
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
        protected VerifyOtpChallenge $verifyOtp,
    ) {}

    public function handle(array $data): array
    {
        $challenge = $this->verifyOtp->handle(
            challengeId: $data['challenge_id'],
            code: $data['code'],
            expectedPurpose: OtpPurpose::Registration,
        );

        $contactType = $challenge->channel;
        $destination = $challenge->encrypted_destination;

        return DB::transaction(function () use ($data, $contactType, $destination, $challenge) {
            $existingContact = PatientAccountContact::where('lookup_hash', $challenge->destination_hash)
                ->where('type', $contactType)
                ->first();

            if ($existingContact !== null) {
                $user = $existingContact->user;
                $isNew = false;
            } else {
                $role = Role::where('name', 'patient')->firstOrFail();

                $middleName = $data['middle_name'] ?? null;
                $fullName = trim($data['first_name'].' '.($middleName ? $middleName.' ' : '').$data['last_name']);

                $user = User::create([
                    'name' => $fullName,
                    'first_name' => $data['first_name'],
                    'middle_name' => $middleName,
                    'last_name' => $data['last_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'email' => null,
                    'phone' => $data['phone'] ?? null,
                    'password' => Hash::make($data['password']),
                    'role_id' => $role->id,
                    'privacy_notice_version' => 'accepted',
                    'privacy_acknowledged_at' => now(),
                ]);

                PatientAccountContact::create([
                    'user_id' => $user->id,
                    'type' => $contactType,
                    'encrypted_value' => $destination,
                    'lookup_hash' => $challenge->destination_hash,
                    'verified_at' => now(),
                    'is_primary' => true,
                ]);

                $isNew = true;
            }

            // Handle invitation code if provided
            if (! empty($data['invitation_code'])) {
                $this->acceptInvitation($data['invitation_code'], $user);
            }

            $token = $user->createToken(
                $data['device_name'] ?? 'mobile',
                ['*'],
                now()->addDays(config('patient_accounts.tokens.expiry_days', 30))
            )->plainTextToken;

            return [
                'token' => $token,
                'user' => $user,
                'is_new' => $isNew,
            ];
        });
    }

    protected function acceptInvitation(string $code, User $user): void
    {
        $invitation = PatientInvitation::where('invitation_code', $code)->first();

        if ($invitation === null || ! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation_code' => ['The invitation code is invalid or has expired.'],
            ]);
        }

        // Check that the invited destination matches the user's verified contact
        $userContact = $user->contacts()
            ->where('type', $invitation->channel)
            ->where('verified_at', '!=', null)
            ->first();

        if ($userContact === null || $userContact->lookup_hash !== $invitation->destination_hash) {
            throw ValidationException::withMessages([
                'invitation_code' => ['The invitation does not match your registered contact.'],
            ]);
        }

        // Check patient is still unlinked
        $patient = $invitation->patient;
        if ($patient->user_id !== null) {
            throw ValidationException::withMessages([
                'invitation_code' => ['The patient record is already linked to another account.'],
            ]);
        }

        // Activate the link
        $patient->update(['user_id' => $user->id]);
        $invitation->accept($user);
    }
}
