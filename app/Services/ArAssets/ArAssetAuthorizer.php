<?php

namespace App\Services\ArAssets;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ArAssetAuthorizer
{
    public function authorize(User $actor): void
    {
        if (! $actor->is_active || ! $actor->isAdmin() && ! $actor->isStaff()) {
            throw new AuthorizationException('Only active staff or administrators may manage AR assets.');
        }
    }
}
