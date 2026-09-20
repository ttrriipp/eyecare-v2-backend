<?php

namespace App\Actions\Encounters;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelEncounter
{
    public function __construct(private CreateAuditLog $createAuditLog) {}

    /**
     * Cancel an encounter created in error.
     *
     * Only completed or planned encounters can be cancelled. Requires an active
     * optometrist or administrator, a reason, and records actor and timestamp.
     */
    public function handle(
        Encounter $encounter,
        User $actor,
        string $reason,
    ): Encounter {
        if (! $actor->is_active || ! ($actor->isOptometrist() || $actor->isAdmin())) {
            throw ValidationException::withMessages([
                'actor' => ['Only an optometrist or administrator may cancel a consultation.'],
            ]);
        }

        if (! in_array($encounter->status, [EncounterStatus::Planned, EncounterStatus::Completed], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only planned or completed consultations can be cancelled.'],
            ]);
        }

        return DB::transaction(function () use ($encounter, $actor, $reason): Encounter {
            $encounter->update([
                'status' => EncounterStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->createAuditLog->handle(
                subject: $encounter,
                action: AuditEvent::EncounterCancelled,
                metadata: [
                    'cancelled_by' => $actor->id,
                    'reason' => $reason,
                ],
                actorId: $actor->id,
            );

            return $encounter->fresh();
        });
    }
}
