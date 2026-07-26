<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrescriptionResource;
use App\Models\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrescriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $prescriptions = Prescription::query()
            ->where('patient_id', $patient->id)
            ->latest('prescribed_at')
            ->paginate($request->integer('per_page', 15));

        return PrescriptionResource::collection($prescriptions);
    }

    public function show(Request $request, Prescription $prescription): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $prescription->patient_id === $patient->id, 404);

        return response()->json([
            'data' => PrescriptionResource::make($prescription),
        ]);
    }
}
