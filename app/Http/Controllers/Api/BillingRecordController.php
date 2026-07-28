<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingRecordController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        return BillingRecord::query()
            ->where('patient_id', $patient->id)
            ->with(['payments' => fn ($q) => $q->where('status', 'posted')])
            ->latest()
            ->paginate($request->integer('per_page', 15));
    }

    public function show(Request $request, BillingRecord $billingRecord): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $billingRecord->patient_id === $patient->id, 404);

        $billingRecord->load(['payments' => fn ($q) => $q->where('status', 'posted')]);

        return response()->json(['data' => $billingRecord]);
    }
}
