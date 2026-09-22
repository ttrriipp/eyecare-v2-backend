<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePatientRole
{
    /**
     * Ensure the authenticated account has the patient role.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $hasPatientRole = $user->isPatient()
            || ($user->roles()->doesntExist() && $user->role?->name === Role::Patient);

        if (! $hasPatientRole) {
            return response()->json([
                'error' => [
                    'code' => 'PATIENT_ROLE_REQUIRED',
                    'message' => 'A patient account is required.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
