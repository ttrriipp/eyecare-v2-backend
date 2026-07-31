<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\BeginPatientLogin;
use App\Actions\Auth\DispatchOtpChallenge;
use App\Actions\Auth\IssuePatientDeviceToken;
use App\Actions\Auth\RegisterPatientAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\PatientLoginRequest;
use App\Http\Requests\Api\PatientLoginVerifyRequest;
use App\Http\Requests\Api\RegisterPatientAccountRequest;
use App\Http\Requests\Api\RegisterPatientRequest;
use App\Http\Requests\Api\UpdateMeRequest;
use App\Http\Resources\PatientAccountResource;
use App\Http\Resources\PatientProfileResource;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

            $patient = Patient::query()->create([
                'user_id' => $user->id,
                'full_name' => $data['name'],
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

    public function patientLoginVerify(PatientLoginVerifyRequest $request, IssuePatientDeviceToken $issueToken, DispatchOtpChallenge $dispatch): JsonResponse
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
        $user->load('role', 'patient');

        return response()->json([
            'data' => PatientProfileResource::make($user),
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

        // Separate account fields from patient fields
        $accountFields = array_intersect_key($validated, array_flip(['name', 'email', 'phone', 'address']));
        $patientFields = array_intersect_key($validated, array_flip([
            'full_name', 'date_of_birth', 'occupation', 'gender', 'contact_email',
        ]));

        DB::transaction(function () use ($user, $accountFields, $patientFields): void {
            if ($accountFields !== []) {
                $user->update($accountFields);
            }

            if ($patientFields !== [] && $user->patient !== null) {
                $user->patient->update($patientFields);
            }
        });

        $user->load('role', 'patient');

        return response()->json([
            'data' => PatientProfileResource::make($user),
        ]);
    }
}
