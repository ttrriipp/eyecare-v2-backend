<?php

namespace App\Http\Controllers\Api;

use App\Enums\IntakeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePatientIntakeRequest;
use App\Http\Resources\PatientIntakeResource;
use App\Models\Appointment;
use App\Models\PatientIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientIntakeController extends Controller
{
    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $appointment->patient_id === $patient->id, 404);

        $intake = PatientIntake::query()
            ->where('appointment_id', $appointment->id)
            ->latest()
            ->first();

        if ($intake === null) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => PatientIntakeResource::make($intake),
        ]);
    }

    public function upsert(StorePatientIntakeRequest $request, Appointment $appointment): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $appointment->patient_id === $patient->id, 403);

        $data = $request->validated();

        $intake = PatientIntake::query()
            ->where('appointment_id', $appointment->id)
            ->where('patient_id', $patient->id)
            ->first();

        if ($intake !== null) {
            // Only draft intakes can be edited
            if ($intake->status !== IntakeStatus::Draft) {
                return response()->json(['message' => 'Only draft intakes can be edited.'], 422);
            }

            $intake->update($data);
        } else {
            // Snapshot appointment type from appointment
            $intake = PatientIntake::query()->create([
                ...$data,
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id,
                'status' => IntakeStatus::Draft,
                'appointment_type' => $appointment->appointmentType?->name,
                'full_name' => $data['full_name'] ?? $patient->full_name,
                'phone' => $data['phone'] ?? $patient->phone,
                'email' => $data['email'] ?? $patient->contact_email,
            ]);
        }

        return response()->json([
            'data' => PatientIntakeResource::make($intake->fresh()),
        ], $intake->wasRecentlyCreated ? 201 : 200);
    }

    public function submit(Request $request, Appointment $appointment): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $appointment->patient_id === $patient->id, 403);

        $intake = PatientIntake::query()
            ->where('appointment_id', $appointment->id)
            ->where('patient_id', $patient->id)
            ->first();

        abort_unless($intake !== null, 404);

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
}
