<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\SubmitAppointmentRescheduleRequest;
use App\Actions\Appointments\WithdrawAppointmentRescheduleRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAppointmentRescheduleRequest;
use App\Http\Resources\AppointmentRescheduleRequestResource;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class AppointmentRescheduleRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $patient = $request->user()->patient;
        abort_unless($patient !== null, 403);

        $perPage = min(max($request->integer('per_page', 15), 1), 50);
        $requests = AppointmentRescheduleRequest::query()
            ->where('user_id', $request->user()->id)
            ->where('patient_id', $patient->id)
            ->with(['appointment.status'])
            ->latest('created_at')
            ->paginate($perPage);

        return AppointmentRescheduleRequestResource::collection($requests);
    }

    public function store(
        StoreAppointmentRescheduleRequest $request,
        Appointment $appointment,
        SubmitAppointmentRescheduleRequest $submit,
    ): JsonResponse {
        $patient = $request->user()->patient;
        abort_unless($patient !== null && $appointment->patient_id === $patient->id, 404);

        $rescheduleRequest = $submit->handle(
            account: $request->user(),
            appointment: $appointment,
            requestedScheduledAt: Carbon::parse(
                $request->validated('requested_scheduled_at'),
                config('app.timezone'),
            ),
            alternativeScheduledTimes: array_map(
                fn (string $time): Carbon => Carbon::parse($time, config('app.timezone')),
                $request->validated('alternative_scheduled_times', []),
            ),
            reasonDetails: $request->validated('reason_details'),
        );

        return AppointmentRescheduleRequestResource::make($rescheduleRequest)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, AppointmentRescheduleRequest $appointmentRescheduleRequest): AppointmentRescheduleRequestResource
    {
        $patient = $request->user()->patient;
        abort_unless(
            $patient !== null
                && $appointmentRescheduleRequest->user_id === $request->user()->id
                && $appointmentRescheduleRequest->patient_id === $patient->id,
            404,
        );

        $appointmentRescheduleRequest->load('appointment.status');

        return AppointmentRescheduleRequestResource::make($appointmentRescheduleRequest);
    }

    public function cancel(
        Request $request,
        AppointmentRescheduleRequest $appointmentRescheduleRequest,
        WithdrawAppointmentRescheduleRequest $withdraw,
    ): JsonResponse {
        $rescheduleRequest = $withdraw->handle(
            request: $appointmentRescheduleRequest,
            account: $request->user(),
        );

        return AppointmentRescheduleRequestResource::make($rescheduleRequest)
            ->response();
    }
}
