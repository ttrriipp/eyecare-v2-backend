<?php

namespace App\Policies;

use App\Models\Encounter;
use App\Models\User;

class EncounterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function view(User $user, Encounter $encounter): bool
    {
        return $user->hasPanelRole();
    }

    public function assign(User $user, Encounter $encounter): bool
    {
        return $user->hasPanelRole();
    }

    public function start(User $user, Encounter $encounter): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->isOptometrist()) {
            return false;
        }

        // Can self-claim if unassigned
        if ($encounter->optometrist_id === null) {
            return true;
        }

        // Must be the assigned optometrist
        return $encounter->optometrist_id === $user->id;
    }

    public function edit(User $user, Encounter $encounter): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->isOptometrist()) {
            return false;
        }

        // Must be the assigned optometrist
        return $encounter->optometrist_id === $user->id;
    }

    public function complete(User $user, Encounter $encounter): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->isOptometrist()) {
            return false;
        }

        // Must be the assigned optometrist
        return $encounter->optometrist_id === $user->id;
    }

    public function transfer(User $user, Encounter $encounter): bool
    {
        if (! $user->is_active) {
            return false;
        }

        // Current provider can transfer
        return $encounter->optometrist_id === $user->id && $user->isOptometrist();
    }

    public function transferAsAdmin(User $user, Encounter $encounter): bool
    {
        // Plain administrator can transfer (operational, not clinical)
        return $user->isAdmin();
    }

    public function addCorrection(User $user, Encounter $encounter): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->isOptometrist()) {
            return false;
        }

        // Only the original completing provider
        return $encounter->completed_by === $user->id;
    }

    public function addSupplement(User $user, Encounter $encounter): bool
    {
        if (! $user->is_active) {
            return false;
        }

        // Active optometrists can supplement
        return $user->isOptometrist();
    }

    public function void(User $user, Encounter $encounter): bool
    {
        if (! $user->is_active) {
            return false;
        }

        // Clinical authority to void findings, or administrative authority to
        // void an encounter raised in error.
        return $user->isOptometrist() || $user->isAdmin();
    }

    public function print(User $user, Encounter $encounter): bool
    {
        return $user->hasPanelRole();
    }

    public function delete(User $user, Encounter $encounter): bool
    {
        return false;
    }

    public function restore(User $user, Encounter $encounter): bool
    {
        return false;
    }
}
