<?php

namespace App\Actions\PatientAccounts;

use App\Enums\PatientInvitationStatus;
use App\Models\Patient;
use App\Models\PatientInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssuePatientInvitation
{
    public function __construct(
        protected CreateContactLookupHash $lookupHash,
    ) {}

    public function handle(Patient $patient, string $channel, User $sender): PatientInvitation
    {
        if ($patient->user_id !== null) {
            throw ValidationException::withMessages([
                'patient' => ['This patient is already linked to an account.'],
            ]);
        }

        $destination = $channel === 'email' ? $patient->contact_email : $patient->phone;

        if (empty($destination)) {
            throw ValidationException::withMessages([
                'channel' => ["The patient does not have a {$channel} on record."],
            ]);
        }

        $destinationHash = $channel === 'email'
            ? $this->lookupHash->forEmail($destination)
            : $this->lookupHash->forPhone($destination);

        return DB::transaction(function () use ($patient, $channel, $sender, $destination, $destinationHash) {
            // Revoke existing active invitation for same patient/destination
            PatientInvitation::where('patient_id', $patient->id)
                ->where('destination_hash', $destinationHash)
                ->where('status', PatientInvitationStatus::Pending)
                ->update([
                    'status' => PatientInvitationStatus::Revoked,
                    'revoked_at' => now(),
                ]);

            $secret = Str::random(32);

            $invitation = PatientInvitation::create([
                'patient_id' => $patient->id,
                'sender_id' => $sender->id,
                'channel' => $channel,
                'encrypted_destination' => $destination,
                'destination_hash' => $destinationHash,
                'secret_digest' => Hash::make($secret),
                'status' => PatientInvitationStatus::Pending,
                'expires_at' => now()->addDays(config('patient_accounts.invitations.lifetime_days', 7)),
                'sent_at' => now(),
            ]);

            return $invitation;
        });
    }
}
