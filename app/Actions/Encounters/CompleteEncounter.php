<?php

namespace App\Actions\Encounters;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\AppointmentStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteEncounter
{
    public function handle(
        Encounter $encounter,
        User $actor,
    ): Encounter {
        if ($encounter->status !== EncounterStatus::InProgress) {
            throw ValidationException::withMessages([
                'encounter' => ['Only in-progress encounters can be completed.'],
            ]);
        }

        // Normally requires the assigned optometrist; dual-role admin optometrist can also complete
        if ($encounter->optometrist_id !== $actor->id && ! $actor->isOptometrist()) {
            throw ValidationException::withMessages([
                'actor' => ['Only the assigned optometrist or an admin optometrist can complete this encounter.'],
            ]);
        }

        return DB::transaction(function () use ($encounter, $actor): Encounter {
            $encounter->update([
                'status' => EncounterStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->id,
            ]);

            // Fulfill the appointment when encounter is completed
            $appointment = $encounter->appointment;
            if ($appointment !== null) {
                $appointment->update([
                    'appointment_status_id' => AppointmentStatus::query()
                        ->where('name', 'fulfilled')
                        ->value('id'),
                    'fulfilled_at' => now(),
                ]);
            }

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $encounter,
                action: AuditEvent::EncounterCompleted->value,
                metadata: [
                    'appointment_id' => $encounter->appointment_id,
                    'optometrist_id' => $encounter->optometrist_id,
                    'actor_id' => $actor->id,
                    'admin_override' => $encounter->optometrist_id !== $actor->id,
                ],
            );

            return $encounter->fresh(['appointment', 'optometrist']);
        });
    }
}
