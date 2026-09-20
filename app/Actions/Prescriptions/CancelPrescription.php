<?php

namespace App\Actions\Prescriptions;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelPrescription
{
    public function __construct(private CreateAuditLog $createAuditLog) {}

    /**
     * Cancel a prescription created in error.
     *
     * Only the latest version in a chain can be cancelled. Requires a reason
     * and records the actor and timestamp.
     */
    public function handle(
        Prescription $prescription,
        User $actor,
        string $reason,
    ): Prescription {
        if (! $actor->isOptometrist()) {
            throw ValidationException::withMessages([
                'actor' => ['Only an optometrist may cancel a prescription.'],
            ]);
        }

        if ($prescription->isCancelled()) {
            throw ValidationException::withMessages([
                'prescription' => ['This prescription is already cancelled.'],
            ]);
        }

        return DB::transaction(function () use ($prescription, $actor, $reason): Prescription {
            $prescription->update([
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->createAuditLog->handle(
                subject: $prescription,
                action: AuditEvent::PrescriptionCancelled,
                metadata: [
                    'cancelled_by' => $actor->id,
                    'reason' => $reason,
                ],
                actorId: $actor->id,
            );

            return $prescription->fresh();
        });
    }
}
