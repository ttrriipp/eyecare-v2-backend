<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function present(User $user, Quotation $quotation): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function decide(User $user, Quotation $quotation): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function revise(User $user, Quotation $quotation): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }
}
