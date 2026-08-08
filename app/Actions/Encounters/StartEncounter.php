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
        User $optometrist,
        User $actor,
    ): Encounter {
        if ($encounter->status !== EncounterStatus::Planned) {
            throw ValidationException::withMessages([
                'encounter' => ['Only planned encounters can be started.'],
            ]);
        }

        if (! $actor->isOptometrist()) {
            throw ValidationException::withMessages([
                'actor' => ['Only an optometrist can start an encounter.'],
            ]);
        }

        if (! $optometrist->isOptometrist()) {
            throw ValidationException::withMessages([
                'optometrist_id' => ['The selected user is not an optometrist.'],
            ]);
        }

        return DB::transaction(function () use ($encounter, $optometrist, $actor): Encounter {
            $appointment = $encounter->appointment;

            if ($appointment === null || $appointment->status->name !== 'checked_in') {
                throw ValidationException::withMessages([
                    'encounter' => ['The appointment must be checked in before starting the encounter.'],
                ]);
            }

            // Atomically update encounter
            // Appointment stays checked_in while encounter is in_progress
            $encounter->update([
                'status' => EncounterStatus::InProgress,
                'optometrist_id' => $optometrist->id,
                'started_at' => now(),
            ]);

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $encounter,
                action: AuditEvent::EncounterStarted->value,
                metadata: [
                    'appointment_id' => $appointment->id,
                    'optometrist_id' => $optometrist->id,
                    'actor_id' => $actor->id,
                ],
            );

            return $encounter->fresh(['appointment', 'optometrist']);
        });
    }
}
