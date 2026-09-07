<?php

namespace App\Policies;

use App\Models\AppointmentRescheduleRequest;
use App\Models\User;

class AppointmentRescheduleRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPanelRole();
    }

    public function view(User $user, AppointmentRescheduleRequest $request): bool
    {
        return $user->is_active && $user->hasPanelRole();
    }
}
