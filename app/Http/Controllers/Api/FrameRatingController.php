<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ratings\SaveFrameRating;
use App\Enums\JobOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\FrameRatingResource;
use App\Models\DispensingEvent;
use App\Models\JobOrderItem;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FrameRatingController extends Controller
{
    public function store(Request $request, JobOrderItem $item): JsonResponse
    {
        $patient = $request->user()->patient;

        abort_unless($patient !== null, 422, 'No patient record linked to this account.');

        // Authorization: job-order item must belong to the authenticated patient
        $jobOrder = $item->jobOrder;

        abort_unless($jobOrder->patient_id === $patient->id, 403, 'This job order item does not belong to you.');

        // Authorization: job order must be dispensed
        abort_unless($jobOrder->status === JobOrderStatus::Dispensed, 422, 'Only dispensed job orders can be rated.');

        $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'dispensing_event_id' => ['nullable', 'integer', 'exists:dispensing_events,id'],
        ]);

        // Derive product_variant_id from the item when not provided
        $productVariantId = $request->input('product_variant_id') ?? $item->product_variant_id;

        // Authorization: product_variant_id must match the job-order item when provided
        if ($request->filled('product_variant_id')) {
            abort_unless(
                $item->product_variant_id === $request->integer('product_variant_id'),
                422,
                'The product variant does not match this job order item.',
            );
        }

        $variant = ProductVariant::findOrFail($productVariantId);

        // Authorization: dispensing_event_id must belong to the same patient/job order
        $dispensingEvent = null;
        if ($request->filled('dispensing_event_id')) {
            $dispensingEvent = DispensingEvent::findOrFail($request->integer('dispensing_event_id'));

            abort_unless(
                $dispensingEvent->job_order_id === $jobOrder->id,
                422,
                'The dispensing event does not belong to this job order.',
            );
        }

        $rating = app(SaveFrameRating::class)->handle(
            patient: $patient,
            variant: $variant,
            rating: $request->integer('rating'),
            comment: $request->input('comment'),
            dispensingEvent: $dispensingEvent,
        );

        $rating->load('currentRevision');

        return response()->json(['data' => FrameRatingResource::make($rating)], 201);
    }
}
