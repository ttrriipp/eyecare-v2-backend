<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function view(User $user, User $account): bool
    {
        return $user->hasPanelRole();
    }

    public function linkPatientRecord(User $user, User $account): bool
    {
        return $user->isAdmin() && $account->patient === null;
    }

    public function unlinkPatientRecord(User $user, User $account): bool
    {
        return $user->isAdmin() && $account->patient !== null;
    }
}
