<?php

namespace App\Actions\Encounters;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartEncounter
{
    public function handle(
        Encounter $encounter,
        User $actor,
    ): Encounter {
        if ($encounter->status !== EncounterStatus::Planned) {
            throw ValidationException::withMessages([
                'encounter' => ['Only planned encounters can be started.'],
            ]);
        }

        if (! $actor->is_active) {
            throw ValidationException::withMessages([
                'actor' => ['Inactive accounts cannot start encounters.'],
            ]);
        }

        if (! $actor->isOptometrist()) {
            throw ValidationException::withMessages([
                'actor' => ['Only an optometrist can start an encounter.'],
            ]);
        }

        return DB::transaction(function () use ($encounter, $actor): Encounter {
            $appointment = $encounter->appointment;

            if ($appointment === null || $appointment->status->name !== 'checked_in') {
                throw ValidationException::withMessages([
                    'encounter' => ['The appointment must be checked in before starting the encounter.'],
                ]);
            }

            // If assigned, only the assigned optometrist can start
            if ($encounter->optometrist_id !== null && $encounter->optometrist_id !== $actor->id) {
                throw ValidationException::withMessages([
                    'actor' => ['Only the assigned optometrist can start this encounter.'],
                ]);
            }

            // Self-claim if unassigned, otherwise keep existing assignment
            $optometristId = $encounter->optometrist_id ?? $actor->id;

            // Update encounter
            $encounter->update([
                'status' => EncounterStatus::InProgress,
                'optometrist_id' => $optometristId,
                'started_at' => now(),
            ]);

            // Synchronize appointment provider
            $appointment->update([
                'optometrist_id' => $optometristId,
            ]);

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $encounter,
                action: AuditEvent::EncounterStarted->value,
                metadata: [
                    'appointment_id' => $appointment->id,
                    'optometrist_id' => $optometristId,
                    'actor_id' => $actor->id,
                ],
                actorId: $actor->id,
            );

            return $encounter->fresh(['appointment', 'optometrist']);
        });
    }
}
