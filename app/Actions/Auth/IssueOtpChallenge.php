<?php

namespace App\Actions\Auth;

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class IssueOtpChallenge
{
    public function __construct(
        protected CreateContactLookupHash $lookupHash,
    ) {}

    public function handle(
        string $contactType,
        string $contactValue,
        OtpPurpose $purpose,
        ?int $userId = null,
    ): OtpChallenge {
        $normalizedValue = $contactType === 'email'
            ? app(NormalizeContact::class)->email($contactValue)
            : app(NormalizeContact::class)->phone($contactValue);

        $destinationHash = $contactType === 'email'
            ? $this->lookupHash->forEmail($normalizedValue)
            : $this->lookupHash->forPhone($normalizedValue);

        $this->invalidateEarlierChallenges($destinationHash, $purpose);

        $code = $this->generateCode();

        $challenge = OtpChallenge::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'purpose' => $purpose,
            'channel' => $contactType,
            'encrypted_destination' => $normalizedValue,
            'destination_hash' => $destinationHash,
            'code_digest' => Hash::make($code),
            'attempts' => 0,
            'max_attempts' => config('patient_accounts.otp.max_attempts', 5),
            'expires_at' => now()->addMinutes(config('patient_accounts.otp.lifetime_minutes', 10)),
            'last_sent_at' => now(),
            'delivery_status' => 'pending',
        ]);

        return $challenge;
    }

    protected function invalidateEarlierChallenges(string $destinationHash, OtpPurpose $purpose): void
    {
        OtpChallenge::query()
            ->where('destination_hash', $destinationHash)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->update(['invalidated_at' => now()]);
    }

    protected function generateCode(): string
    {
        $length = config('patient_accounts.otp.length', 6);

        return str_pad((string) random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
