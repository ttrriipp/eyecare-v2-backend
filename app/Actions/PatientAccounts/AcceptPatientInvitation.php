<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Actions\Conversations\AssociateAccountConversation;
use App\Enums\AuditEvent;
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
        protected CreateContactLookupHash $lookupHash,
        protected CreateAuditLog $createAuditLog,
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
                ->lockForUpdate()
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

            $patient = $invitation->patient()
                ->lockForUpdate()
                ->firstOrFail();

            if ($patient->user_id !== null) {
                throw ValidationException::withMessages([
                    'invitation_code' => ['The patient is already linked to another account.'],
                ]);
            }

            $destination = $invitation->encrypted_destination;
            $destinationHash = $invitation->destination_hash;
            $existingContact = PatientAccountContact::query()
                ->where('lookup_hash', $destinationHash)
                ->where('type', $invitation->channel)
                ->lockForUpdate()
                ->first();

            if ($authenticatedUser !== null) {
                $user = User::query()
                    ->whereKey($authenticatedUser->id)
                    ->lockForUpdate()
                    ->firstOrFail();

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
                $user = User::query()
                    ->whereKey($existingContact->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

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

            $patient->update(['user_id' => $user->id]);
            $invitation->accept($user);

            $this->createAuditLog->handle(
                subject: $patient,
                action: AuditEvent::PatientAccountLinked,
                metadata: [
                    'account_id' => $user->id,
                    'invitation_id' => $invitation->id,
                    'channel' => $invitation->channel,
                ],
                actorId: $user->id,
            );

            // Associate the account's conversation with the Patient
            app(AssociateAccountConversation::class)->handle($user, $patient);

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
