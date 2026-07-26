<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function restartWorkflow(User $user, Complaint $complaint): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }
}
