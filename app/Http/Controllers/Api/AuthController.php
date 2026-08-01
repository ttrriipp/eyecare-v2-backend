<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\BeginPatientLogin;
use App\Actions\Auth\DispatchOtpChallenge;
use App\Actions\Auth\IssueOtpChallenge;
use App\Actions\Auth\IssuePatientDeviceToken;
use App\Actions\Auth\RecoverPatientPassword;
use App\Actions\Auth\RegisterPatientAccount;
use App\Actions\Auth\VerifyStepUpOtp;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\PatientLoginRequest;
use App\Http\Requests\Api\PatientLoginVerifyRequest;
use App\Http\Requests\Api\RegisterPatientAccountRequest;
use App\Http\Requests\Api\RegisterPatientRequest;
use App\Http\Requests\Api\RegistrationVerifyRequest;
use App\Http\Requests\Api\StepUpOtpRequest;
use App\Http\Requests\Api\UpdateMeRequest;
use App\Http\Resources\PatientAccountResource;
use App\Http\Resources\PatientProfileResource;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterPatientRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$user, $patient] = DB::transaction(function () use ($data): array {
            $patientRole = Role::query()->where('name', 'patient')->firstOrFail();

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role_id' => $patientRole->id,
            ]);

            $nameParts = explode(' ', trim($data['name']), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            $patient = Patient::query()->create([
                'user_id' => $user->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'contact_email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            return [$user, $patient];
        });

        $user->load('role', 'patient');

        return response()->json([
            'data' => [
                'token' => $user->createToken('mobile')->plainTextToken,
                'user' => PatientProfileResource::make($user),
            ],
        ], 201);
    }

    /**
     * Step 1: Verify OTP and return registration token.
     */
    public function registrationVerify(RegistrationVerifyRequest $request, RegisterPatientAccount $register): JsonResponse
    {
        $result = $register->verifyRegistration(
            challengeId: $request->validated('challenge_id'),
            code: $request->validated('code'),
        );

        return response()->json([
            'data' => [
                'registration_token' => $result['registration_token'],
                'expires_at' => $result['expires_at']->toISOString(),
                'contact_type' => $result['contact_type'],
            ],
        ]);
    }

    /**
     * Step 2: Complete registration with profile data.
     */
    public function registerWithOtp(RegisterPatientAccountRequest $request, RegisterPatientAccount $register): JsonResponse
    {
        $result = $register->handle($request->validated());

        $user = $result['user'];
        $user->load('role', 'contacts');

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => PatientAccountResource::make($user),
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();
        $user->load('role', 'patient');

        return response()->json([
            'data' => [
                'token' => $user->createToken('mobile')->plainTextToken,
                'user' => PatientProfileResource::make($user),
            ],
        ]);
    }

    public function patientLogin(PatientLoginRequest $request, BeginPatientLogin $login): JsonResponse
    {
        $result = $login->handle(
            contactValue: $request->validated('contact_value'),
            password: $request->validated('password'),
        );

        return response()->json([
            'data' => [
                'step_up_required' => $result['step_up_required'],
                'challenge_id' => $result['challenge_id'],
                'expires_at' => $result['expires_at']->toISOString(),
            ],
        ]);
    }

    public function patientLoginVerify(PatientLoginVerifyRequest $request, IssuePatientDeviceToken $issueToken): JsonResponse
    {
        $result = $issueToken->handle(
            challengeId: $request->validated('challenge_id'),
            code: $request->validated('code'),
            deviceName: $request->validated('device_name'),
            installationId: $request->validated('installation_id'),
        );

        $user = $result['user'];
        $user->load('role', 'contacts');

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => PatientAccountResource::make($user),
            ],
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('role', 'contacts', 'patient');

        return response()->json([
            'data' => PatientAccountResource::make($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json();
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(null, 204);
    }

    public function update(UpdateMeRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $accountFields = array_intersect_key($validated, array_flip(['first_name', 'middle_name', 'last_name']));

        DB::transaction(function () use ($user, $accountFields): void {
            if ($accountFields !== []) {
                $user->update($accountFields);
            }
        });

        $user->load('role', 'contacts');

        return response()->json([
            'data' => PatientAccountResource::make($user),
        ]);
    }

    /**
     * Request step-up OTP for sensitive changes.
     */
    public function requestStepUp(StepUpOtpRequest $request, IssueOtpChallenge $issueOtp, DispatchOtpChallenge $dispatch): JsonResponse
    {
        $user = $request->user();

        // Find the user's primary contact
        $primaryContact = $user->contacts()
            ->where('is_primary', true)
            ->whereNotNull('verified_at')
            ->first();

        if ($primaryContact === null) {
            return response()->json([
                'error' => [
                    'code' => 'NO_VERIFIED_CONTACT',
                    'message' => 'No verified contact found for step-up verification.',
                ],
            ], 422);
        }

        $challenge = $issueOtp->handle(
            contactType: $primaryContact->type,
            contactValue: $primaryContact->encrypted_destination,
            purpose: OtpPurpose::SensitiveChange,
            userId: $user->id,
        );

        $dispatch->handle($challenge);

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->public_id,
                'expires_at' => $challenge->expires_at->toISOString(),
                'contact_type' => $primaryContact->type,
                'masked_contact' => $this->maskContact($primaryContact->encrypted_destination, $primaryContact->type),
            ],
        ]);
    }

    /**
     * Verify step-up OTP and return proof token.
     */
    public function verifyStepUp(Request $request, VerifyStepUpOtp $verifyStepUp): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $stepUpToken = $verifyStepUp->handle(
            challengeId: $request->input('challenge_id'),
            code: $request->input('code'),
            user: $request->user(),
        );

        return response()->json([
            'data' => [
                'step_up_token' => $stepUpToken,
                'expires_in' => 900, // 15 minutes
            ],
        ]);
    }

    /**
     * Change password with step-up verification.
     */
    public function changePassword(ChangePasswordRequest $request, VerifyStepUpOtp $verifyStepUp): JsonResponse
    {
        $user = $request->user();

        // Validate step-up token
        if (! $verifyStepUp->validateStepUpToken($request->input('step_up_token'), $user)) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STEP_UP_TOKEN',
                    'message' => 'The step-up verification is invalid or has expired.',
                ],
            ], 422);
        }

        // Verify current password
        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_PASSWORD',
                    'message' => 'The current password is incorrect.',
                ],
            ], 422);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        // Revoke other tokens
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json([
            'data' => [
                'message' => 'Password changed successfully.',
            ],
        ]);
    }

    /**
     * Request recovery OTP.
     */
    public function recoveryOtp(Request $request, RecoverPatientPassword $recover, IssueOtpChallenge $issueOtp, DispatchOtpChallenge $dispatch): JsonResponse
    {
        $request->validate(['contact_value' => ['required', 'string']]);

        $user = $recover->findUserByContact($request->input('contact_value'));

        if ($user !== null) {
            $challenge = $issueOtp->handle(
                contactType: 'email',
                contactValue: $request->input('contact_value'),
                purpose: OtpPurpose::PasswordRecovery,
                userId: $user->id,
            );

            $dispatch->handle($challenge);
        }

        return response()->json([
            'data' => [
                'message' => 'If the contact is associated with an account, a recovery code has been sent.',
            ],
        ]);
    }

    /**
     * Verify recovery OTP and reset password.
     */
    public function recoveryVerify(Request $request, RecoverPatientPassword $recover): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'installation_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $recover->handle(
            challengeId: $request->input('challenge_id'),
            code: $request->input('code'),
            newPassword: $request->input('password'),
        );

        $user = $result['user'];
        $user->load('role', 'contacts');

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => PatientAccountResource::make($user),
            ],
        ]);
    }

    protected function maskContact(string $value, string $type): string
    {
        if ($type === 'email') {
            $parts = explode('@', $value);
            if (count($parts) === 2) {
                return substr($parts[0], 0, 1).'***@'.$parts[1];
            }
        }

        if (strlen($value) >= 4) {
            return substr($value, 0, 3).'***'.substr($value, -4);
        }

        return '***';
    }
}
