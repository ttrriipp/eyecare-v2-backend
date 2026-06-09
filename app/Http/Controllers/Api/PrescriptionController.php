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
        $prescriptions = Prescription::query()
            ->where('customer_id', $request->user()->id)
            ->latest('prescribed_at')
            ->get();

        return PrescriptionResource::collection($prescriptions);
    }

    public function show(Request $request, Prescription $prescription): JsonResponse
    {
        abort_unless($prescription->customer_id === $request->user()->id, 404);

        return response()->json([
            'data' => PrescriptionResource::make($prescription),
        ]);
    }
}
