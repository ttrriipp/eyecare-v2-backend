<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function view(User $user, Patient $patient): bool
    {
        if ($user->hasPanelRole()) {
            return true;
        }

        return $user->patient?->id === $patient->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function update(User $user, Patient $patient): bool
    {
        if ($user->hasPanelRole()) {
            return true;
        }

        return $user->patient?->id === $patient->id;
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user): bool
    {
        return $user->isAdmin();
    }
}
