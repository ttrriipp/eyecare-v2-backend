<?php

namespace App\Policies;

use App\Models\BillingRecord;
use App\Models\User;

class BillingRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function view(User $user, BillingRecord $billingRecord): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function recordPayment(User $user, BillingRecord $billingRecord): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function correctPayment(User $user, BillingRecord $billingRecord): bool
    {
        return $user->isAdmin();
    }

    public function void(User $user, BillingRecord $billingRecord): bool
    {
        return $user->isAdmin();
    }
}
