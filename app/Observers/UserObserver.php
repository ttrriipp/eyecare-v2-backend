<?php

namespace App\Observers;

use App\Actions\Audit\CreateAuditLog;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        app(CreateAuditLog::class)->handle($user, 'user.created');
    }

    public function updated(User $user): void
    {
        if (! $user->wasChanged('role_id')) {
            return;
        }

        app(CreateAuditLog::class)->handle(
            subject: $user,
            action: 'user.role_changed',
            metadata: [
                'from_role_id' => $user->getOriginal('role_id'),
                'to_role_id' => $user->role_id,
            ],
        );
    }
}
