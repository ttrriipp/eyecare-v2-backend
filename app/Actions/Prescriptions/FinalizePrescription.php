<?php

namespace App\Actions\Prescriptions;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FinalizePrescription
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(
        Patient $patient,
        Encounter $encounter,
        User $author,
        array $data,
        ?Prescription $previousPrescription = null,
    ): Prescription {
        if (! $author->hasOptometristCapability()) {
            throw ValidationException::withMessages([
                'author' => ['Only an optometrist can finalize a prescription.'],
            ]);
        }

        return Prescription::query()->create([
            ...$data,
            'patient_id' => $patient->id,
            'encounter_id' => $encounter->id,
            'previous_prescription_id' => $previousPrescription?->id,
            'created_by' => $author->id,
            'prescribed_at' => Carbon::now(),
        ]);
    }
}
