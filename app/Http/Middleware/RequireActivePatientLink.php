<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActivePatientLink
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->patient === null) {
            return response()->json([
                'error' => [
                    'code' => 'ACTIVE_PATIENT_LINK_REQUIRED',
                    'message' => 'An active patient link is required to access this resource.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
