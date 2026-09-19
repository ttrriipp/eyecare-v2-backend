<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\OtpPurpose;
use App\Enums\PatientInvitationStatus;
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
        protected LinkPatientAccount $linkPatientAccount,
    ) {}

    public function handle(
        string $invitationCode,
        string $challengeId,
        string $code,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $password = null,
        ?User $authenticatedUser = null,
        ?string $ip = null,
    ): array {
        return DB::transaction(function () use (
            $invitationCode,
            $challengeId,
            $code,
            $firstName,
            $lastName,
            $password,
            $authenticatedUser,
            $ip,
        ): array {
            $invitation = PatientInvitation::query()
                ->where('invitation_code', $invitationCode)
                ->first();

            if ($invitation === null) {
                throw ValidationException::withMessages([
                    'invitation_code' => ['The invitation code is invalid.'],
                ]);
            }

            if (! $invitation->isPending()) {
                if ($this->isIdempotentRetry($invitation, $authenticatedUser)) {
                    return $this->resultForAcceptedInvitation($invitation, $authenticatedUser);
                }

                throw ValidationException::withMessages([
                    'invitation_code' => ['The invitation has expired, been revoked, or already accepted.'],
                ]);
            }

            $this->verifyOtp->handle(
                challengeId: $challengeId,
                code: $code,
                expectedPurpose: OtpPurpose::InvitationAcceptance,
                ip: $ip,
                expectedUserId: $authenticatedUser?->id,
            );

            $destination = $invitation->encrypted_destination;
            $destinationHash = $invitation->destination_hash;
            $existingContact = PatientAccountContact::query()
                ->where('lookup_hash', $destinationHash)
                ->where('type', $invitation->channel)
                ->first();

            $accountId = $authenticatedUser?->id ?? $existingContact?->user_id;
            $user = $accountId === null
                ? null
                : User::query()->lockForUpdate()->findOrFail($accountId);

            // Lock order: account -> invitation -> Patient -> contacts.
            $invitation = PatientInvitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $invitation->isPending()) {
                if ($this->isIdempotentRetry($invitation, $authenticatedUser)) {
                    return $this->resultForAcceptedInvitation($invitation, $authenticatedUser);
                }

                throw ValidationException::withMessages([
                    'invitation_code' => ['The invitation has expired, been revoked, or already accepted.'],
                ]);
            }

            $patient = $invitation->patient()
                ->lockForUpdate()
                ->firstOrFail();

            // Read identity-bound invitation fields from the locked row so a
            // concurrent revocation or administrative update cannot affect
            // the account/contact checks below.
            $destination = $invitation->encrypted_destination;
            $destinationHash = $invitation->destination_hash;

            if ($patient->user_id !== null) {
                throw ValidationException::withMessages([
                    'invitation_code' => ['The patient is already linked to another account.'],
                ]);
            }

            $existingContact = PatientAccountContact::query()
                ->where('lookup_hash', $destinationHash)
                ->where('type', $invitation->channel)
                ->lockForUpdate()
                ->first();

            if ($authenticatedUser !== null) {
                if (! $user instanceof User) {
                    throw ValidationException::withMessages([
                        'invitation_code' => ['The invitation does not match the authenticated account.'],
                    ]);
                }

                $matchingContact = PatientAccountContact::query()
                    ->where('user_id', $user->id)
                    ->where('lookup_hash', $destinationHash)
                    ->where('type', $invitation->channel)
                    ->whereNotNull('verified_at')
                    ->exists();

                if (! $matchingContact || ($existingContact !== null && $existingContact->user_id !== $user->id)) {
                    throw ValidationException::withMessages([
                        'invitation_code' => ['The invitation does not match the authenticated account.'],
                    ]);
                }

                if ($user->patient()->exists()) {
                    throw ValidationException::withMessages([
                        'invitation_code' => ['The account is already linked to a patient.'],
                    ]);
                }
            } elseif ($existingContact !== null) {
                if (! $user instanceof User) {
                    throw ValidationException::withMessages([
                        'invitation_code' => ['The invitation account could not be resolved.'],
                    ]);
                }

                if ($user->patient()->exists()) {
                    throw ValidationException::withMessages([
                        'invitation_code' => ['The account is already linked to a patient.'],
                    ]);
                }
            } else {
                $role = Role::query()->where('name', Role::Patient)->firstOrFail();

                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password' => Hash::make($password ?? Str::random(32)),
                    'role_id' => $role->id,
                ]);

                $user->roles()->sync([$role->id]);

                PatientAccountContact::create([
                    'user_id' => $user->id,
                    'type' => $invitation->channel,
                    'encrypted_value' => $destination,
                    'lookup_hash' => $destinationHash,
                    'verified_at' => now(),
                    'is_primary' => true,
                ]);
            }

            $this->linkPatientAccount->handle(
                account: $user,
                patient: $patient,
                source: 'patient_invitation',
                sourceId: $invitation->id,
                actorId: $user->id,
            );
            $invitation->accept($user);

            return $this->resultForAcceptedInvitation($invitation, $user);
        });
    }

    private function isIdempotentRetry(PatientInvitation $invitation, ?User $authenticatedUser): bool
    {
        return $authenticatedUser !== null
            && $invitation->status === PatientInvitationStatus::Accepted
            && $invitation->accepted_by_user_id === $authenticatedUser->id
            && $invitation->patient()->where('user_id', $authenticatedUser->id)->exists();
    }

    /**
     * @return array{token: string, user: User, invitation: PatientInvitation}
     */
    private function resultForAcceptedInvitation(PatientInvitation $invitation, User $user): array
    {
        $user->load('patient');

        return [
            'token' => $user->createToken('mobile', ['*'], now()->addDays(30))->plainTextToken,
            'user' => $user,
            'invitation' => $invitation,
        ];
    }
}
