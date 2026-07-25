<?php

namespace App\Http\Controllers\Api;

use App\Actions\Intakes\VerifyPatientIntake;
use App\Enums\IntakeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePatientIntakeRequest;
use App\Http\Resources\PatientIntakeResource;
use App\Models\PatientIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PatientIntakeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $intakes = PatientIntake::query()
            ->where('patient_id', $patient->id)
            ->latest()
            ->get();

        return PatientIntakeResource::collection($intakes);
    }

    public function store(StorePatientIntakeRequest $request): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 422, 'No patient record linked to this account.');

        $data = $request->validated();

        $intake = PatientIntake::query()->create([
            ...$data,
            'patient_id' => $patient->id,
            'status' => IntakeStatus::Draft,
            'full_name' => $data['full_name'] ?? $patient->full_name,
            'phone' => $data['phone'] ?? $patient->phone,
            'email' => $data['email'] ?? $patient->contact_email,
        ]);

        return response()->json([
            'data' => PatientIntakeResource::make($intake),
        ], 201);
    }

    public function update(StorePatientIntakeRequest $request, PatientIntake $intake): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $intake->patient_id === $patient->id, 403);

        if ($intake->status === IntakeStatus::Verified) {
            return response()->json(['message' => 'Verified intakes cannot be edited.'], 422);
        }

        $intake->update($request->validated());

        return response()->json([
            'data' => PatientIntakeResource::make($intake->fresh()),
        ]);
    }

    public function submit(Request $request, PatientIntake $intake): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $intake->patient_id === $patient->id, 403);

        if ($intake->status !== IntakeStatus::Draft) {
            return response()->json(['message' => 'Only draft intakes can be submitted.'], 422);
        }

        $intake->update([
            'status' => IntakeStatus::Submitted,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'data' => PatientIntakeResource::make($intake->fresh()),
        ]);
    }

    public function verify(Request $request, PatientIntake $intake): JsonResponse
    {
        Gate::forUser($request->user())->authorize('verify', $intake);

        app(VerifyPatientIntake::class)->handle($intake, $request->user());

        return response()->json([
            'data' => PatientIntakeResource::make($intake->fresh()),
        ]);
    }
}
