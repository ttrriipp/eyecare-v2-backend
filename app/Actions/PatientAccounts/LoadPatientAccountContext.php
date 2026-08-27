<?php

namespace App\Actions\PatientAccounts;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoadPatientAccountContext
{
    public function handle(User $account): User
    {
        return $account->load([
            'role',
            'contacts',
            'patient',
            'linkRequests' => fn (HasMany $query): HasMany => $query
                ->where('status', 'pending')
                ->latest('id'),
        ]);
    }
}
