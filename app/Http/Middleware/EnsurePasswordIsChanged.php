<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Routes a user with a pending forced password change must still be able
     * to reach: the profile page itself (the redirect target) and logout.
     */
    private const ALLOWED_ROUTES = [
        'filament.admin.auth.profile',
        'filament.admin.auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        return redirect(Filament::getProfileUrl())
            ->with('status', 'Please change your password before continuing.');
    }
}
