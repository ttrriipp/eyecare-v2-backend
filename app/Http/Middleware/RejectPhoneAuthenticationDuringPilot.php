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
        if (config('capstone_pilot.enabled') === true
            || config('deployment.mode') === 'demo') {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
