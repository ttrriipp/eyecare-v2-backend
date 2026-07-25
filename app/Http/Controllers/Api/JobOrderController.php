<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobOrderController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        return JobOrder::query()
            ->where('patient_id', $patient->id)
            ->with('items')
            ->latest()
            ->paginate($request->integer('per_page', 15));
    }

    public function show(Request $request, JobOrder $jobOrder): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $jobOrder->patient_id === $patient->id, 404);

        $jobOrder->load('items');

        return response()->json(['data' => $jobOrder]);
    }
}
