<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;

class StaffAppointmentController extends Controller
{
    public function updateStatus(
        UpdateAppointmentStatusRequest $request,
        Appointment $appointment,
        UpdateAppointmentStatus $updateAppointmentStatus,
    ): JsonResponse {
        $appointment = $updateAppointmentStatus->handle(
            appointment: $appointment,
            statusName: $request->validated('status'),
            staffNotes: $request->validated('staff_notes'),
        );

        return response()->json([
            'data' => AppointmentResource::make($appointment),
        ]);
    }
}
