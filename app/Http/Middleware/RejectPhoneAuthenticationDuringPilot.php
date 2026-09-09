<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RejectPhoneAuthenticationDuringPilot
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $deploymentMode = config('deployment.mode');

        if ($deploymentMode === 'patient-demo') {
            if (config('deployment.phone_auth_enabled') === true
                && config('capstone_pilot.enabled') !== true) {
                return $next($request);
            }

            throw new NotFoundHttpException;
        }

        if (config('capstone_pilot.enabled') === true
            || $deploymentMode === 'demo') {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
