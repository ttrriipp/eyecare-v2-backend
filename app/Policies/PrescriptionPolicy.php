<?php

namespace App\Policies;

use App\Models\Prescription;
use App\Models\User;

class PrescriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function view(User $user, Prescription $prescription): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasOptometristCapability();
    }

    public function finalize(User $user): bool
    {
        return $user->hasOptometristCapability();
    }

    public function amend(User $user, Prescription $prescription): bool
    {
        return $user->hasOptometristCapability() && ! $prescription->trashed();
    }

    public function update(User $user, Prescription $prescription): bool
    {
        return false;
    }
}
