<?php

namespace App\Actions\Ratings;

use App\Enums\CommercialItemKind;
use App\Models\Appointment;
use App\Models\BillingRecordItem;
use App\Models\Patient;
use App\Models\VisitRating;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveVisitRating
{
    /**
     * Create or revise a patient's rating of one fulfilled visit.
     *
     * One current rating per appointment. Edits update in place.
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
            $lockedAppointment = Appointment::query()
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            $existing = VisitRating::query()
                ->where('appointment_id', $lockedAppointment->id)
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'rating' => $rating,
                    'comment' => $comment,
                ]);

                return $existing->fresh();
            }

            $encounter = $lockedAppointment->encounter;
            $optometristId = $encounter?->optometrist_id;
            $serviceIds = $this->resolveServiceIds($lockedAppointment);

            return VisitRating::query()->create([
                'patient_id' => $patient->id,
                'appointment_id' => $lockedAppointment->id,
                'encounter_id' => $encounter?->id,
                'optometrist_id' => $optometristId,
                'rating' => $rating,
                'comment' => $comment,
                'service_ids' => $serviceIds,
            ]);
        });
    }

    /**
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
