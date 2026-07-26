<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ratings\SaveFrameRating;
use App\Http\Controllers\Controller;
use App\Models\DispensingEvent;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FrameRatingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 422, 'No patient record linked to this account.');

        $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'dispensing_event_id' => ['nullable', 'integer', 'exists:dispensing_events,id'],
        ]);

        $variant = ProductVariant::findOrFail($request->integer('product_variant_id'));
        $dispensingEvent = $request->filled('dispensing_event_id')
            ? DispensingEvent::find($request->integer('dispensing_event_id'))
            : null;

        $rating = app(SaveFrameRating::class)->handle(
            patient: $patient,
            variant: $variant,
            rating: $request->integer('rating'),
            comment: $request->input('comment'),
            dispensingEvent: $dispensingEvent,
        );

        return response()->json(['data' => $rating->load('revisions')], 201);
    }
}
