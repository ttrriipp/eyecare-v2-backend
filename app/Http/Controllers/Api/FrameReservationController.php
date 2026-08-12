<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\ClinicSchedule;
use App\Actions\Reservations\CreateFrameReservation;
use App\Actions\Reservations\DeleteFrameReservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFrameReservationRequest;
use App\Http\Resources\FrameReservationResource;
use App\Models\Appointment;
use App\Models\FrameReservation;
use Carbon\Carbon;
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

    public function destroy(Request $request, FrameReservation $reservation): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $reservation->patient_id === $patient->id, 403);

        app(DeleteFrameReservation::class)->handle($reservation);

        return response()->json(null, 204);
    }

    public static function deriveExpiresAt(FrameReservation $reservation): ?string
    {
        $appointmentDate = $reservation->appointment?->scheduled_at;

        if ($appointmentDate === null) {
            return null;
        }

        $schedule = ClinicSchedule::forDate($appointmentDate);

        return Carbon::parse($appointmentDate->toDateString().' '.$schedule->closeTime)->toIso8601String();
    }
}
