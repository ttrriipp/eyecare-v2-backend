<?php

namespace App\Actions\Intakes;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\IntakeStatus;
use App\Models\PatientIntake;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReturnIntakeForCorrection
{
    public function handle(PatientIntake $intake, User $actor, ?string $reason = null): PatientIntake
    {
        if ($intake->status !== IntakeStatus::Submitted) {
            throw ValidationException::withMessages([
                'intake' => ['Only submitted records can be returned for correction.'],
            ]);
        }

        $intake->update([
            'status' => IntakeStatus::Draft,
            'submitted_by' => null,
            'submitted_at' => null,
        ]);

        app(CreateAuditLog::class)->handle(
            subject: $intake,
            action: AuditEvent::IntakeReturnedForCorrection,
            metadata: array_filter([
                'actor_id' => $actor->id,
                'reason' => $reason,
            ]),
        );

        return $intake->fresh();
    }
}
