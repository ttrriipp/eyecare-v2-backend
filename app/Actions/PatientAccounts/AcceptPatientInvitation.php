<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\OtpPurpose;
use App\Models\PatientAccountContact;
use App\Models\PatientInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptPatientInvitation
{
    public function __construct(
        protected VerifyOtpChallenge $verifyOtp,
        protected CreateContactLookupHash $lookupHash,
    ) {}

    public function handle(
        string $invitationCode,
        string $challengeId,
        string $code,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $password = null,
    ): array {
        // Find invitation by invitation_code
        $invitation = PatientInvitation::where('invitation_code', $invitationCode)->first();

        if ($invitation === null) {
            throw ValidationException::withMessages([
                'invitation_code' => ['The invitation code is invalid.'],
            ]);
        }

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation_code' => ['The invitation has expired, been revoked, or already accepted.'],
            ]);
        }

        // Verify the OTP
        $challenge = $this->verifyOtp->handle(
            challengeId: $challengeId,
            code: $code,
            expectedPurpose: OtpPurpose::InvitationAcceptance,
        );

        return DB::transaction(function () use ($invitation, $firstName, $lastName, $password) {
            // Re-check invitation under lock
            $invitation->lockForUpdate();

            if (! $invitation->isPending()) {
                throw ValidationException::withMessages([
                    'invitation_code' => ['The invitation is no longer valid.'],
                ]);
            }

            $patient = $invitation->patient;

            // Check patient still unlinked
            if ($patient->user_id !== null) {
                throw ValidationException::withMessages([
                    'invitation_code' => ['The patient is already linked to another account.'],
                ]);
            }

            // Find or create the user account
            $destination = $invitation->encrypted_destination;
            $destinationHash = $invitation->destination_hash;

            $existingContact = PatientAccountContact::where('lookup_hash', $destinationHash)
                ->where('type', $invitation->channel)
                ->first();

            if ($existingContact !== null) {
                $user = $existingContact->user;

                // Check account not already linked
                if ($user->patient !== null) {
                    throw ValidationException::withMessages([
                        'invitation_code' => ['The account is already linked to a patient.'],
                    ]);
                }
            } else {
                // Create new account
                $role = Role::where('name', 'patient')->firstOrFail();

                $user = User::create([
                    'name' => ($firstName ?? 'Patient').' '.($lastName ?? ''),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password' => Hash::make($password ?? Str::random(32)),
                    'role_id' => $role->id,
                ]);

                PatientAccountContact::create([
                    'user_id' => $user->id,
                    'type' => $invitation->channel,
                    'encrypted_value' => $destination,
                    'lookup_hash' => $destinationHash,
                    'verified_at' => now(),
                    'is_primary' => true,
                ]);
            }

            // Activate the link
            $patient->update(['user_id' => $user->id]);

            // Accept the invitation
            $invitation->accept($user);

            // Issue token
            $token = $user->createToken('mobile', ['*'], now()->addDays(30))->plainTextToken;

            return [
                'token' => $token,
                'user' => $user,
                'invitation' => $invitation,
            ];
        });
    }
}
