<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdatePatientProfileRequest;
use App\Http\Resources\PatientProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PatientProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        if ($patient === null) {
            return response()->json(['message' => 'No patient record linked to this account.'], 404);
        }

        Gate::forUser($request->user())->authorize('view', $patient);

        return response()->json([
            'data' => PatientProfileResource::make($request->user())->resolve($request),
        ]);
    }

    public function update(UpdatePatientProfileRequest $request): JsonResponse
    {
        $patient = $request->user()->patient;

        if ($patient === null) {
            return response()->json(['message' => 'No patient record linked to this account.'], 404);
        }

        Gate::forUser($request->user())->authorize('update', $patient);

        $patient->update($request->validated());

        $request->user()->load('patient');

        return response()->json([
            'data' => PatientProfileResource::make($request->user())->resolve($request),
        ]);
    }
}
