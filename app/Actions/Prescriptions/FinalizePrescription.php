<?php

namespace App\Actions\Prescriptions;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalizePrescription
{
    public function __construct(private CreateAuditLog $createAuditLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(
        Patient $patient,
        Encounter $encounter,
        User $author,
        array $data,
        ?Prescription $previousPrescription = null,
        ?string $amendmentReason = null,
    ): Prescription {
        if (! $author->hasOptometristCapability()) {
            throw ValidationException::withMessages([
                'author' => ['Only an optometrist can finalize a prescription.'],
            ]);
        }

        return DB::transaction(function () use ($patient, $encounter, $author, $data, $previousPrescription, $amendmentReason): Prescription {
            $lockedEncounter = Encounter::query()
                ->lockForUpdate()
                ->findOrFail($encounter->id);

            if ($lockedEncounter->patient_id !== $patient->id) {
                throw ValidationException::withMessages([
                    'patient' => ['The prescription patient must match the encounter patient.'],
                ]);
            }

            if ($previousPrescription === null) {
                if ($lockedEncounter->status !== EncounterStatus::InProgress) {
                    throw ValidationException::withMessages([
                        'encounter' => ['A prescription can only be finalized during an in-progress encounter.'],
                    ]);
                }

                if ($lockedEncounter->prescriptions()->withTrashed()->exists()) {
                    throw ValidationException::withMessages([
                        'encounter' => ['This encounter already has a finalized prescription. Create an amendment instead.'],
                    ]);
                }
            } else {
                $canonicalPreviousPrescription = Prescription::query()
                    ->whereKey($previousPrescription->id)
                    ->lockForUpdate()
                    ->first();

                if ($canonicalPreviousPrescription === null) {
                    throw ValidationException::withMessages([
                        'previous_prescription' => ['The prescription being amended is unavailable.'],
                    ]);
                }

                if (! in_array($lockedEncounter->status, [EncounterStatus::InProgress, EncounterStatus::Completed], true)) {
                    throw ValidationException::withMessages([
                        'encounter' => ['A prescription amendment requires an in-progress or completed encounter.'],
                    ]);
                }

                if ($canonicalPreviousPrescription->patient_id !== $patient->id
                    || $canonicalPreviousPrescription->encounter_id !== $lockedEncounter->id) {
                    throw ValidationException::withMessages([
                        'previous_prescription' => ['The prior prescription must belong to the same patient and encounter.'],
                    ]);
                }

                if (blank($amendmentReason)) {
                    throw ValidationException::withMessages([
                        'amendment_reason' => ['An amendment reason is required.'],
                    ]);
                }

                if (mb_strlen($amendmentReason) > 1000) {
                    throw ValidationException::withMessages([
                        'amendment_reason' => ['The amendment reason must not exceed 1000 characters.'],
                    ]);
                }

                if (Prescription::query()
                    ->withTrashed()
                    ->where('previous_prescription_id', $canonicalPreviousPrescription->id)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'previous_prescription' => ['This prescription has already been superseded. Amend the latest version instead.'],
                    ]);
                }

                $previousPrescription = $canonicalPreviousPrescription;
            }

            $prescription = $lockedEncounter->prescriptions()->create([
                ...$data,
                'patient_id' => $lockedEncounter->patient_id,
                'appointment_id' => $lockedEncounter->appointment_id,
                'previous_prescription_id' => $previousPrescription?->id,
                'amendment_reason' => $previousPrescription === null
                    ? null
                    : trim($amendmentReason),
                'created_by' => $author->id,
                'prescribed_at' => Carbon::now(),
            ]);

            $this->createAuditLog->handle(
                subject: $prescription,
                action: $previousPrescription === null
                    ? AuditEvent::PrescriptionFinalized
                    : AuditEvent::PrescriptionAmended,
                metadata: $previousPrescription === null
                    ? ['encounter_id' => $lockedEncounter->id]
                    : [
                        'encounter_id' => $lockedEncounter->id,
                        'previous_prescription_id' => $previousPrescription->id,
                    ],
                actorId: $author->id,
            );

            return $prescription;
        });
    }
}
