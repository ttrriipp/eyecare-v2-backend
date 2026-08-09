<?php

namespace App\Actions\Encounters;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignEncounterOptometrist
{
    public function handle(
        Encounter $encounter,
        User $actor,
        User $optometrist,
    ): Encounter {
        if ($encounter->status !== EncounterStatus::Planned) {
            throw ValidationException::withMessages([
                'encounter' => ['Only planned encounters can have their provider assigned.'],
            ]);
        }

        if (! $actor->hasPanelRole()) {
            throw ValidationException::withMessages([
                'actor' => ['Only panel users can assign an optometrist.'],
            ]);
        }

        if (! $optometrist->is_active || ! $optometrist->isOptometrist()) {
            throw ValidationException::withMessages([
                'optometrist_id' => ['The selected user is not an active optometrist.'],
            ]);
        }

        return DB::transaction(function () use ($encounter, $actor, $optometrist): Encounter {
            $lockedEncounter = Encounter::query()
                ->whereKey($encounter->id)
                ->lockForUpdate()
                ->first();

            if ($lockedEncounter->status !== EncounterStatus::Planned) {
                throw ValidationException::withMessages([
                    'encounter' => ['This encounter is no longer in a planned state.'],
                ]);
            }

            $previousOptometristId = $lockedEncounter->optometrist_id;

            // Update encounter
            $lockedEncounter->update([
                'optometrist_id' => $optometrist->id,
            ]);

            // Synchronize appointment provider
            if ($lockedEncounter->appointment !== null) {
                $lockedEncounter->appointment->update([
                    'optometrist_id' => $optometrist->id,
                ]);
            }

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $lockedEncounter,
                action: AuditEvent::EncounterProviderAssigned->value,
                metadata: [
                    'appointment_id' => $lockedEncounter->appointment_id,
                    'previous_optometrist_id' => $previousOptometristId,
                    'new_optometrist_id' => $optometrist->id,
                    'actor_id' => $actor->id,
                ],
            );

            return $lockedEncounter->fresh(['appointment', 'optometrist']);
        });
    }
}
