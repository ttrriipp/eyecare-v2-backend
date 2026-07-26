<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterPatientRequest;
use App\Http\Requests\Api\UpdateMeRequest;
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
