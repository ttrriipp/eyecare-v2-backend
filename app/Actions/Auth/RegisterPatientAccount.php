<?php

namespace App\Actions\Auth;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use App\Enums\OtpPurpose;
use App\Models\PatientAccountContact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

                $user = User::create([
                    'name' => $data['first_name'].' '.$data['last_name'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'email' => null,
                    'phone' => null,
                    'password' => Hash::make($data['password']),
                    'role_id' => $role->id,
                    'privacy_notice_version' => $data['privacy_notice_version'],
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
}
