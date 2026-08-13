<?php

namespace App\Actions\Encounters;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VoidEncounter
{
    public function __construct(private CreateAuditLog $createAuditLog) {}

    /**
     * Void an encounter created in error.
     *
     * Only completed or planned encounters can be voided. Requires an active
     * optometrist or administrator, a reason, and records actor and timestamp.
     */
    public function handle(
        Encounter $encounter,
        User $actor,
        string $reason,
    ): Encounter {
        if (! $actor->is_active || ! ($actor->isOptometrist() || $actor->isAdmin())) {
            throw ValidationException::withMessages([
                'actor' => ['Only an optometrist or administrator may void an encounter.'],
            ]);
        }

        if (! in_array($encounter->status, [EncounterStatus::Planned, EncounterStatus::Completed], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only planned or completed encounters can be voided.'],
            ]);
        }

        return DB::transaction(function () use ($encounter, $actor, $reason): Encounter {
            $encounter->update([
                'status' => EncounterStatus::Voided,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $this->createAuditLog->handle(
                subject: $encounter,
                action: 'encounter.voided',
                metadata: [
                    'voided_by' => $actor->id,
                    'reason' => $reason,
                ],
                actorId: $actor->id,
            );

            return $encounter->fresh();
        });
    }
}
