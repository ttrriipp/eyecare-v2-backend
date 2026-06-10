<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $appointments = Appointment::query()
            ->where('customer_id', $request->user()->id)
            ->with(['visitReason', 'status'])
            ->latest('scheduled_at')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $pendingStatus = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();

        $appointment = Appointment::query()->create([
            ...$request->validated(),
            'customer_id' => $request->user()->id,
            'appointment_status_id' => $pendingStatus->id,
        ]);

        $appointment->load(['visitReason', 'status']);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ], 201);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->customer_id === $request->user()->id, 404);

        $appointment->load(['visitReason', 'status']);

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }
}
