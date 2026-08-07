<?php

namespace App\Http\Controllers\Api;

use App\Enums\JobOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\OpticalOrderResource;
use App\Models\JobOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OpticalOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $request->validate([
            'filter' => ['nullable', 'string', Rule::in(['current', 'history'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $filter = $request->input('filter', 'current');

        $orders = JobOrder::query()
            ->where('patient_id', $patient->id)
            ->when($filter === 'current', fn ($query) => $query->whereIn('status', [
                JobOrderStatus::Queued,
                JobOrderStatus::InProgress,
                JobOrderStatus::ReadyForDispensing,
            ]))
            ->when($filter === 'history', fn ($query) => $query->whereIn('status', [
                JobOrderStatus::Dispensed,
                JobOrderStatus::Cancelled,
            ]))
            ->with(['items', 'quotation', 'billingRecord'])
            ->latest()
            ->latest('id')
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
