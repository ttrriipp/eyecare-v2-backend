<?php

namespace App\Policies;

use App\Models\User;

class PrescriptionPolicy
{
    public function finalize(User $user): bool
    {
        return $user->hasOptometristCapability();
    }

    public function amend(User $user): bool
    {
        return $user->hasOptometristCapability();
    }
}
