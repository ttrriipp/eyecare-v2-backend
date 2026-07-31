<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\CancelAppointmentRequest;
use App\Actions\Appointments\SubmitAppointmentRequest;
use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AppointmentRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requests = AppointmentRequest::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'data' => $requests->items(),
            'links' => [
                'first' => $requests->url(1),
                'last' => $requests->url($requests->lastPage()),
                'prev' => $requests->previousPageUrl(),
                'next' => $requests->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(Request $request, SubmitAppointmentRequest $submit): JsonResponse
    {
        $validated = $request->validate([
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
            'reason_for_visit' => ['required', 'string', 'max:1000'],
        ]);

        $appointmentRequest = $submit->handle(
            account: $request->user(),
            scheduledAt: Carbon::parse($validated['scheduled_at']),
            reasonForVisit: $validated['reason_for_visit'],
        );

        return response()->json([
            'data' => $this->formatRequest($appointmentRequest),
        ], 201);
    }

    public function show(Request $request, AppointmentRequest $appointmentRequest): JsonResponse
    {
        if ($appointmentRequest->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No query results for model.'], 404);
        }

        return response()->json([
            'data' => $this->formatRequest($appointmentRequest),
        ]);
    }

    public function cancel(Request $request, AppointmentRequest $appointmentRequest, CancelAppointmentRequest $cancel): JsonResponse
    {
        $result = $cancel->handle($appointmentRequest, $request->user());

        return response()->json([
            'data' => $this->formatRequest($result),
        ]);
    }

    protected function formatRequest(AppointmentRequest $request): array
    {
        return [
            'id' => $request->id,
            'request_number' => $request->request_number,
            'status' => $request->status->value ?? $request->status,
            'patient_id' => $request->patient_id,
            'scheduled_at' => $request->scheduled_at->toISOString(),
            'reason_for_visit' => $request->encrypted_reason_for_visit,
            'expires_at' => $request->expires_at->toISOString(),
            'created_at' => $request->created_at->toISOString(),
            'appointment' => $request->appointment_id ? [
                'id' => $request->appointment?->id,
                'appointment_number' => $request->appointment?->appointment_number,
                'status' => $request->appointment?->status?->name,
                'scheduled_at' => $request->appointment?->scheduled_at?->toISOString(),
                'duration_minutes' => $request->appointment?->duration_minutes,
            ] : null,
        ];
    }
}
