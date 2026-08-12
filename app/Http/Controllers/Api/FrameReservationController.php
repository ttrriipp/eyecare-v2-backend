<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reservations\AddFrameReservationItem;
use App\Actions\Reservations\CreateFrameReservation;
use App\Actions\Reservations\DeleteFrameReservation;
use App\Actions\Reservations\RemoveFrameReservationItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFrameReservationRequest;
use App\Http\Resources\FrameReservationResource;
use App\Models\Appointment;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function storeItem(Request $request, FrameReservation $reservation): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $reservation->patient_id === $patient->id, 403);

        if ($reservation->isHeld()) {
            throw ValidationException::withMessages([
                'reservation' => ['Cannot add frames to an accepted reservation.'],
            ]);
        }

        $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
        ]);

        $item = app(AddFrameReservationItem::class)->handle(
            reservation: $reservation,
            productVariantId: (int) $request->input('product_variant_id'),
        );

        $reservation->load(['items.variant.product', 'appointment']);

        return response()->json([
            'data' => FrameReservationResource::make($reservation),
        ], 200);
    }

    public function destroyItem(Request $request, FrameReservation $reservation, FrameReservationItem $item): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null && $reservation->patient_id === $patient->id, 403);
        abort_unless($item->frame_reservation_id === $reservation->id, 404);

        app(RemoveFrameReservationItem::class)->handle($reservation, $item);

        if (! $reservation->exists) {
            return response()->json(null, 204);
        }

        $reservation->load(['items.variant.product', 'appointment']);

        return response()->json([
            'data' => FrameReservationResource::make($reservation),
        ], 200);
    }
}
