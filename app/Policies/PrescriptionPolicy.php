<?php

namespace App\Policies;

use App\Models\Prescription;
use App\Models\User;

class PrescriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function view(User $user, Prescription $prescription): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isOptometrist();
    }

    public function finalize(User $user): bool
    {
        return $user->isOptometrist();
    }

    public function amend(User $user, Prescription $prescription): bool
    {
        return $user->isOptometrist() && ! $prescription->trashed();
    }

    public function update(User $user, Prescription $prescription): bool
    {
        return false;
    }
}
