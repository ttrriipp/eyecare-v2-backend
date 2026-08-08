<?php

namespace App\Policies;

use App\Models\BillingRecord;
use App\Models\User;

class BillingRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function view(User $user, BillingRecord $billingRecord): bool
    {
        return $user->hasPanelRole();
    }

    public function recordPayment(User $user): bool
    {
        return $user->hasPanelRole();
    }

    public function voidPayment(User $user): bool
    {
        return $user->isAdmin();
    }

    public function correctPayment(User $user): bool
    {
        return $user->isAdmin();
    }
}
