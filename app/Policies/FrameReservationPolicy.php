<?php

namespace App\Policies;

use App\Models\FrameReservation;
use App\Models\User;

class FrameReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function view(User $user, FrameReservation $reservation): bool
    {
        return $user->hasPanelRole();
    }

    public function create(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function reserveFrames(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, FrameReservation $reservation): bool
    {
        return $user->hasPanelRole();
    }

    public function addOrRemoveItems(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function addFrame(User $user, FrameReservation $reservation): bool
    {
        return $this->addOrRemoveItems($user, $reservation);
    }

    public function removeFrame(User $user, FrameReservation $reservation): bool
    {
        return $this->addOrRemoveItems($user, $reservation);
    }
}
