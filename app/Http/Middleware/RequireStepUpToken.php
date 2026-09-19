<?php

namespace App\Http\Middleware;

use App\Actions\Auth\VerifyStepUpOtp;
use App\Actions\PatientAccounts\NormalizeContact;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStepUpToken
{
    public function __construct(
        protected VerifyStepUpOtp $verifyStepUp,
        protected NormalizeContact $normalizeContact,
    ) {}

    public function handle(Request $request, Closure $next, string ...$requiredFields): Response
    {
        if (! $this->requiresStepUp($request, $requiredFields)) {
            $request->attributes->set('step_up_verified', false);

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

        $request->attributes->set('step_up_verified', true);

        return $next($request);
    }

    /**
     * Determine whether the submitted fields represent a protected change.
     * Linked first/last names are conditional; DOB and unconditionally-listed
     * fields retain their existing step-up requirement.
     *
     * @param  list<string>  $requiredFields
     */
    private function requiresStepUp(Request $request, array $requiredFields): bool
    {
        if ($requiredFields === []) {
            return true;
        }

        // Allow the request's normal FormRequest validation to report
        // unsupported profile fields before any proof is consumed.
        if (array_diff(array_keys($request->all()), [
            'first_name',
            'middle_name',
            'last_name',
            'date_of_birth',
        ]) !== []) {
            return false;
        }

        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $request->all())) {
                continue;
            }

            if (! in_array($field, ['first_name', 'last_name'], true)) {
                return true;
            }

            $account = $request->user();

            if (! $account instanceof User || $account->patient === null) {
                continue;
            }

            $submitted = $request->input($field);

            if (! is_string($submitted)) {
                continue;
            }

            if ($this->normalizeContact->name($submitted) !== $this->normalizeContact->name((string) $account->{$field})) {
                return true;
            }
        }

        return false;
    }
}
