<?php

namespace App\Policies;

use App\Models\PrivacyRequest;
use App\Models\User;

class PrivacyRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->name === 'admin';
    }

    public function view(User $user, PrivacyRequest $privacyRequest): bool
    {
        return $user->role->name === 'admin';
    }

    public function create(User $user): bool
    {
        return true; // Any authenticated user can submit a request
    }

    public function process(User $user, PrivacyRequest $privacyRequest): bool
    {
        return $user->role->name === 'admin';
    }
}
