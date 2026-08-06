<?php

namespace App\Listeners;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class RecordAuthenticationAudit
{
    public function __construct(private readonly CreateAuditLog $createAuditLog) {}

    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->createAuditLog->handle($user, AuditEvent::UserLoggedIn, actorId: $user->id);
    }

    public function handleLogout(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            return;
        }

        $this->createAuditLog->handle($user, AuditEvent::UserLoggedOut, actorId: $user->id);
    }

    public function handleFailed(Failed $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            return;
        }

        $this->createAuditLog->handle($user, AuditEvent::UserLoginFailed, actorId: $user->id);
    }
}
