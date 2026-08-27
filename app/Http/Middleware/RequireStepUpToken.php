<?php

namespace App\Http\Middleware;

use App\Actions\Auth\VerifyStepUpOtp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStepUpToken
{
    public function __construct(
        protected VerifyStepUpOtp $verifyStepUp,
    ) {}

    public function handle(Request $request, Closure $next, string ...$requiredFields): Response
    {
        if ($requiredFields !== [] && ! collect($requiredFields)->contains(
            fn (string $field): bool => array_key_exists($field, $request->all()),
        )) {
            return $next($request);
        }

        $stepUpToken = $request->header('X-Step-Up-Token');

        if (empty($stepUpToken)) {
            return response()->json([
                'error' => [
                    'code' => 'STEP_UP_REQUIRED',
                    'message' => 'A step-up verification token is required for this action. Request one via POST /auth/step-up/otp and POST /auth/step-up/verify.',
                ],
            ], 422);
        }

        if (! $this->verifyStepUp->validateStepUpToken($stepUpToken, $request->user())) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STEP_UP_TOKEN',
                    'message' => 'The step-up token is invalid or has expired. Request a new one via POST /auth/step-up/otp.',
                ],
            ], 422);
        }

        return $next($request);
    }
}
