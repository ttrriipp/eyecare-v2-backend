<?php

namespace App\Actions\Intakes;

use App\Enums\IntakeStatus;
use App\Models\PatientIntake;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class VerifyPatientIntake
{
    public function handle(PatientIntake $intake, User $verifier): PatientIntake
    {
        if ($intake->status !== IntakeStatus::Submitted) {
            throw ValidationException::withMessages([
                'intake' => ['Only submitted intakes can be verified.'],
            ]);
        }

        $intake->update([
            'status' => IntakeStatus::Verified,
            'verified_by' => $verifier->id,
            'verified_at' => Carbon::now(),
        ]);

        return $intake->fresh();
    }
}
