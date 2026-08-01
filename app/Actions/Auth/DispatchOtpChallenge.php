<?php

namespace App\Actions\Auth;

use App\Models\OtpChallenge;

class DispatchOtpChallenge
{
    public function handle(OtpChallenge $challenge): void
    {
        // No-op: OTP delivery is now dispatched directly by IssueOtpChallenge
        // This action is kept for backward compatibility with existing controller signatures
    }
}
