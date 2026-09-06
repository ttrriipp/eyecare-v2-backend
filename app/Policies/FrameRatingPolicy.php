<?php

namespace App\Policies;

use App\Models\FrameRating;
use App\Models\User;

class FrameRatingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAccess($user);
    }

    public function view(User $user, FrameRating $frameRating): bool
    {
        return $this->canAccess($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FrameRating $frameRating): bool
    {
        return $this->canAccess($user);
    }

    public function delete(User $user, FrameRating $frameRating): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FrameRating $frameRating): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FrameRating $frameRating): bool
    {
        return false;
    }

    private function canAccess(User $user): bool
    {
        return $user->is_active && ($user->isAdmin() || $user->isStaff());
    }
}
