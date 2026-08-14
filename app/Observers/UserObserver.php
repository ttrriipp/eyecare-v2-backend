<?php

namespace App\Observers;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        app(CreateAuditLog::class)->handle($user, AuditEvent::UserCreated, actorId: auth()->id());
    }

    public function saving(User $user): void
    {
        if (! $user->exists || ! $user->isDirty('password')) {
            return;
        }

        $user->password_changed_at = now();

        // A user changing their own password (via the profile page) is done.
        // An admin setting a password for someone else forces a change at
        // that person's next login, since the admin now knows the credential.
        $user->must_change_password = auth()->id() !== $user->id;
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('password')) {
            app(CreateAuditLog::class)->handle($user, AuditEvent::UserPasswordChanged, actorId: auth()->id());
        }

        if ($user->wasChanged('is_active')) {
            app(CreateAuditLog::class)->handle(
                $user,
                $user->is_active ? AuditEvent::UserReactivated : AuditEvent::UserDeactivated,
                actorId: auth()->id(),
            );
        }
    }
}
