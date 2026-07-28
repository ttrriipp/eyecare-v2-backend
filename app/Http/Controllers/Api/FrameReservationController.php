<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reservations\CreateFrameReservation;
use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFrameReservationRequest;
use App\Http\Resources\FrameReservationResource;
use App\Models\Appointment;
use App\Models\FrameReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FrameReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        $reservations = FrameReservation::query()
            ->where('patient_id', $patient->id)
            ->with(['items.variant.product', 'appointment'])
            ->latest()
            ->get();

        return response()->json(['data' => FrameReservationResource::collection($reservations)]);
    }

    public function store(StoreFrameReservationRequest $request): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 422, 'No patient record linked to this account.');

        $data = $request->validated();

        $appointment = Appointment::findOrFail($data['appointment_id']);

        $reservation = app(CreateFrameReservation::class)->handle(
            patient: $patient,
            appointment: $appointment,
            items: $data['items'],
        );

        return response()->json([
            'data' => FrameReservationResource::make($reservation),
        ], 201);
    }

    public function cancel(Request $request, FrameReservation $reservation): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $reservation->patient_id === $patient->id, 403);

        if (! in_array($reservation->status, [ReservationStatus::Requested, ReservationStatus::Prepared], true)) {
            return response()->json(['message' => 'This reservation cannot be cancelled.'], 422);
        }

        app(ReleaseFrameReservation::class)->handle($reservation);

        $reservation->update(['status' => ReservationStatus::Cancelled]);

        return response()->json([
            'data' => FrameReservationResource::make($reservation->fresh()->load(['items.variant.product', 'appointment'])),
        ]);
    }
}
