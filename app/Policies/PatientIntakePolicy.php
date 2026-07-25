<?php

namespace App\Policies;

use App\Models\PatientIntake;
use App\Models\User;

class PatientIntakePolicy
{
    public function verify(User $user, PatientIntake $intake): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }
}
