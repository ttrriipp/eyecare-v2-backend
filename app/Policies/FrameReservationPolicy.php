<?php

namespace App\Policies;

use App\Models\FrameReservation;
use App\Models\User;

class FrameReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function view(User $user, FrameReservation $frameReservation): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function reserveFrames(User $user): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function addFrame(User $user, FrameReservation $frameReservation): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function removeFrame(User $user, FrameReservation $frameReservation): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }
}
