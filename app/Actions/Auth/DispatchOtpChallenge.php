<?php

namespace App\Actions\Auth;

use App\Jobs\DeliverOtpChallenge;
use App\Models\OtpChallenge;

class DispatchOtpChallenge
{
    public function handle(OtpChallenge $challenge): void
    {
        if (! $challenge->isPending()) {
            return;
        }

        DeliverOtpChallenge::dispatch($challenge->public_id)
            ->afterCommit();
    }
}
