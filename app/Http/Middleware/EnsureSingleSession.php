<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SingleSessionManager;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSingleSession
{
    public function __construct(private readonly SingleSessionManager $singleSessionManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->singleSessionManager->claim($user, $request->session()->getId())) {
            return $next($request);
        }

        Filament::auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->to(Filament::getLoginUrl())
            ->with('error', 'Your session ended because this account is active in another browser or device.');
    }
}
