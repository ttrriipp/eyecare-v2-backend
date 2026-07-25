<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function view(User $user, Patient $patient): bool
    {
        if (in_array($user->role->name, ['admin', 'staff'], true)) {
            return true;
        }

        return $user->patient?->id === $patient->id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function update(User $user, Patient $patient): bool
    {
        if (in_array($user->role->name, ['admin', 'staff'], true)) {
            return true;
        }

        return $user->patient?->id === $patient->id;
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->role->name === 'admin';
    }

    public function restore(User $user, Patient $patient): bool
    {
        return $user->role->name === 'admin';
    }
}
