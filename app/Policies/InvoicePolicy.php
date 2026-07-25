<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return in_array($user->role->name, ['admin', 'staff'], true);
    }

    public function correctPayment(User $user, Invoice $invoice): bool
    {
        return $user->role->name === 'admin';
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->role->name === 'admin';
    }
}
