<?php

namespace App\Policies;

use App\Models\User;

class PrivacyRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user): bool
    {
        return $user->isAdmin();
    }

    public function handle(User $user): bool
    {
        return $user->isAdmin();
    }
}
