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

    /**
     * Only admin or dual-role owner can apply or change a nonzero discount.
     */
    public function manageDiscount(User $user): bool
    {
        return $user->isAdmin();
    }
}
