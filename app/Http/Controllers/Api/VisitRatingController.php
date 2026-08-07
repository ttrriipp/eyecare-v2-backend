<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ratings\SaveVisitRating;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreVisitRatingRequest;
use App\Http\Resources\VisitRatingResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;

class VisitRatingController extends Controller
{
    /**
     * POST /api/v1/appointments/{appointment}/rating
     *
     * Create or revise the rating for one fulfilled appointment.
     * Upsert semantics: 201 on create, 200 on revise.
     */
    public function store(StoreVisitRatingRequest $request, Appointment $appointment): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 404);

        // Resolve appointment through the patient's own relation
        $appointment = $patient->appointments()->findOrFail($appointment->id);

        $validated = $request->validated();

        $isNew = ! $appointment->visitRating()->exists();

        $rating = app(SaveVisitRating::class)->handle(
            patient: $patient,
            appointment: $appointment,
            rating: $validated['rating'],
            comment: $validated['comment'] ?? null,
        );

        $rating->load('currentRevision');

        return response()->json(
            ['data' => VisitRatingResource::make($rating)],
            $isNew ? 201 : 200,
        );
    }
}
