<?php

namespace App\Policies;

use App\Models\AppointmentRequest;
use App\Models\User;

class AppointmentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPanelRole();
    }

    public function view(User $user, AppointmentRequest $request): bool
    {
        return $user->is_active && $user->hasPanelRole();
    }

    public function accept(User $user, AppointmentRequest $request): bool
    {
        return $user->is_active
            && $user->hasPanelRole()
            && $request->isReadyForScheduleReview();
    }

    public function reject(User $user, AppointmentRequest $request): bool
    {
        return $user->is_active
            && $user->hasPanelRole()
            && $request->isPending();
    }

    public function link(User $user, AppointmentRequest $request): bool
    {
        return $user->is_active
            && $user->hasPanelRole()
            && $request->isPending()
            && $request->needsPatientResolution();
    }
}
