<?php

namespace App\Actions\Prescriptions;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VoidPrescription
{
    public function __construct(private CreateAuditLog $createAuditLog) {}

    /**
     * Void a prescription created in error.
     *
     * Only the latest version in a chain can be voided. Requires a reason
     * and records the actor and timestamp.
     */
    public function handle(
        Prescription $prescription,
        User $actor,
        string $reason,
    ): Prescription {
        if (! $actor->isOptometrist()) {
            throw ValidationException::withMessages([
                'actor' => ['Only an optometrist may void a prescription.'],
            ]);
        }

        if ($prescription->isVoided()) {
            throw ValidationException::withMessages([
                'prescription' => ['This prescription is already voided.'],
            ]);
        }

        return DB::transaction(function () use ($prescription, $actor, $reason): Prescription {
            $prescription->update([
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $this->createAuditLog->handle(
                subject: $prescription,
                action: AuditEvent::PrescriptionVoided,
                metadata: [
                    'voided_by' => $actor->id,
                    'reason' => $reason,
                ],
                actorId: $actor->id,
            );

            return $prescription->fresh();
        });
    }
}
