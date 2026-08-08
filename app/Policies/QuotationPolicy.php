<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $user->hasPanelRole();
    }

    public function create(User $user): bool
    {
        return $user->hasPanelRole();
    }
}
