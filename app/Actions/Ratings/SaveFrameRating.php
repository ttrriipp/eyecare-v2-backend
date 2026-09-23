<?php

namespace App\Actions\Ratings;

use App\Actions\Notifications\NotifyAdminUsers;
use App\Models\DispensingEvent;
use App\Models\FrameRating;
use App\Models\Patient;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveFrameRating
{
    public function __construct(
        private readonly NotifyAdminUsers $notifyAdminUsers,
    ) {}

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
        bool $publicDisplayConsent = false,
    ): FrameRating {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must be between 1 and 5.'],
            ]);
        }

        $publicDisplayConsentAt = $publicDisplayConsent && trim($comment ?? '') !== ''
            ? now()
            : null;
        $shouldNotify = false;

        $frameRating = DB::transaction(function () use ($patient, $variant, $rating, $comment, $dispensingEvent, $publicDisplayConsentAt, &$shouldNotify): FrameRating {
            $existing = FrameRating::query()
                ->where('patient_id', $patient->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $shouldNotify = $rating <= 2
                    && ($existing->rating !== $rating || $existing->comment !== $comment);

                $existing->update([
                    'rating' => $rating,
                    'comment' => $comment,
                    'public_display_consent_at' => $publicDisplayConsentAt,
                ]);

                return $existing->fresh();
            }

            $shouldNotify = $rating <= 2;

            return FrameRating::query()->create([
                'patient_id' => $patient->id,
                'product_variant_id' => $variant->id,
                'dispensing_event_id' => $dispensingEvent?->id,
                'rating' => $rating,
                'comment' => $comment,
                'public_display_consent_at' => $publicDisplayConsentAt,
            ]);
        });

        if ($shouldNotify) {
            $this->notifyAdminUsers->lowFrameRating($frameRating);
        }

        return $frameRating;
    }
}
