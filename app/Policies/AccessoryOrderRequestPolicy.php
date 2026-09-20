<?php

namespace App\Policies;

use App\Models\AccessoryOrderRequest;
use App\Models\User;

class AccessoryOrderRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function view(User $user, AccessoryOrderRequest $accessoryOrderRequest): bool
    {
        return $user->hasPanelRole();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AccessoryOrderRequest $accessoryOrderRequest): bool
    {
        return false;
    }

    public function delete(User $user, AccessoryOrderRequest $accessoryOrderRequest): bool
    {
        return false;
    }
}
