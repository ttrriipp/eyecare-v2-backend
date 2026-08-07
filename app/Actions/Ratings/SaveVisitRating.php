<?php

namespace App\Actions\Ratings;

use App\Models\Appointment;
use App\Models\BillingRecordItem;
use App\Models\Patient;
use App\Models\VisitRating;
use App\Models\VisitRatingRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveVisitRating
{
    /**
     * Create or revise a patient's rating of one fulfilled visit.
     *
     * One current rating per appointment. Edits append revisions rather than
     * overwriting, so the original submission survives moderation review.
     */
    public function handle(
        Patient $patient,
        Appointment $appointment,
        int $rating,
        ?string $comment = null,
    ): VisitRating {
        if ($appointment->status?->name !== 'fulfilled') {
            throw ValidationException::withMessages([
                'appointment' => ['Only fulfilled appointments can be rated.'],
            ]);
        }

        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must be between 1 and 5.'],
            ]);
        }

        return DB::transaction(function () use ($patient, $appointment, $rating, $comment): VisitRating {
            // Lock the appointment row to prevent concurrent first-submit race
            $lockedAppointment = Appointment::query()
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            $existing = VisitRating::query()
                ->where('appointment_id', $lockedAppointment->id)
                ->first();

            if ($existing !== null) {
                // Append a revision
                $nextRevision = ($existing->revisions()->max('revision_number') ?? 0) + 1;

                $revision = VisitRatingRevision::query()->create([
                    'visit_rating_id' => $existing->id,
                    'revision_number' => $nextRevision,
                    'rating' => $rating,
                    'comment' => $comment,
                    'revised_by' => $patient->user_id,
                    'revised_at' => now(),
                ]);

                $existing->update([
                    'rating' => $rating,
                    'comment' => $comment,
                    'current_revision_id' => $revision->id,
                ]);

                return $existing->fresh();
            }

            // Snapshot optometrist and services at submission time
            $encounter = $lockedAppointment->encounter;
            $optometristId = $encounter?->optometrist_id;
            $serviceIds = $this->resolveServiceIds($lockedAppointment);

            // Create new rating
            $visitRating = VisitRating::query()->create([
                'patient_id' => $patient->id,
                'appointment_id' => $lockedAppointment->id,
                'encounter_id' => $encounter?->id,
                'optometrist_id' => $optometristId,
                'rating' => $rating,
                'comment' => $comment,
                'service_ids' => $serviceIds,
            ]);

            // Create initial revision
            $revision = VisitRatingRevision::query()->create([
                'visit_rating_id' => $visitRating->id,
                'revision_number' => 1,
                'rating' => $rating,
                'comment' => $comment,
                'revised_by' => $patient->user_id,
                'revised_at' => now(),
            ]);

            $visitRating->update(['current_revision_id' => $revision->id]);

            return $visitRating->fresh();
        });
    }

    /**
     * Resolve service IDs rendered at this visit.
     *
     * Services are reachable by two paths:
     * 1. Billing items with encounter_id matching the appointment's encounter
     * 2. Billing items belonging to billing records with encounter_id matching
     *
     * @return array<int, int>
     */
    private function resolveServiceIds(Appointment $appointment): array
    {
        $encounter = $appointment->encounter;

        if ($encounter === null) {
            return [];
        }

        return BillingRecordItem::query()
            ->where('item_type', 'service')
            ->whereNotNull('service_id')
            ->where(function ($query) use ($encounter): void {
                $query->where('encounter_id', $encounter->id)
                    ->orWhereIn('billing_record_id', function ($subQuery) use ($encounter): void {
                        $subQuery->select('id')
                            ->from('billing_records')
                            ->where('encounter_id', $encounter->id);
                    });
            })
            ->pluck('service_id')
            ->unique()
            ->values()
            ->all();
    }
}
