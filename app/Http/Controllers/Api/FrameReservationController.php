<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFrameReservationRequest;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
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
            ->with('items.variant.product')
            ->latest()
            ->get();

        return response()->json(['data' => $reservations]);
    }

    public function store(StoreFrameReservationRequest $request): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 422, 'No patient record linked to this account.');

        $data = $request->validated();

        $reservation = FrameReservation::query()->create([
            'patient_id' => $patient->id,
            'appointment_id' => $data['appointment_id'] ?? null,
            'status' => ReservationStatus::Requested,
        ]);

        foreach ($data['items'] as $item) {
            FrameReservationItem::query()->create([
                'frame_reservation_id' => $reservation->id,
                'product_variant_id' => $item['product_variant_id'],
            ]);
        }

        return response()->json([
            'data' => $reservation->load('items.variant'),
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
            'data' => $reservation->fresh(),
        ]);
    }
}
