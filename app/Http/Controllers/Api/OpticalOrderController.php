<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\OpticalOrderResource;
use App\Models\JobOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpticalOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $orders = JobOrder::query()
            ->where('patient_id', $patient->id)
            ->with(['items', 'quotation', 'billingRecord'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => OpticalOrderResource::collection($orders),
            'links' => [
                'first' => $orders->url(1),
                'last' => $orders->url($orders->lastPage()),
                'prev' => $orders->previousPageUrl(),
                'next' => $orders->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, JobOrder $jobOrder): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $jobOrder->patient_id === $patient->id, 404);

        $jobOrder->load(['items', 'quotation', 'billingRecord']);

        return response()->json([
            'data' => OpticalOrderResource::make($jobOrder),
        ]);
    }
}
