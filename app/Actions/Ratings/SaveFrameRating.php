<?php

namespace App\Actions\Ratings;

use App\Models\DispensingEvent;
use App\Models\FrameRating;
use App\Models\Patient;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveFrameRating
{
    /**
     * Create or update a frame rating. Eligibility derives from dispensing.
     *
     * One current rating per patient per dispensed frame. Edits update in place.
     */
    public function handle(
        Patient $patient,
        ProductVariant $variant,
        int $rating,
        ?string $comment = null,
        ?DispensingEvent $dispensingEvent = null,
    ): FrameRating {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must be between 1 and 5.'],
            ]);
        }

        return DB::transaction(function () use ($patient, $variant, $rating, $comment, $dispensingEvent): FrameRating {
            $existing = FrameRating::query()
                ->where('patient_id', $patient->id)
                ->where('product_variant_id', $variant->id)
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'rating' => $rating,
                    'comment' => $comment,
                ]);

                return $existing->fresh();
            }

            return FrameRating::query()->create([
                'patient_id' => $patient->id,
                'product_variant_id' => $variant->id,
                'dispensing_event_id' => $dispensingEvent?->id,
                'rating' => $rating,
                'comment' => $comment,
            ]);
        });
    }
}
