<?php

namespace App\Actions\Encounters;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Enums\EncounterTransferReason;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferEncounter
{
    public function handle(
        Encounter $encounter,
        User $actor,
        User $newOptometrist,
        EncounterTransferReason $reason,
    ): Encounter {
        if ($encounter->status !== EncounterStatus::InProgress) {
            throw ValidationException::withMessages([
                'encounter' => ['Only in-progress consultations can be transferred.'],
            ]);
        }

        // Only current provider or admin can transfer
        $isCurrentProvider = $encounter->optometrist_id === $actor->id && $actor->isOptometrist();
        $isAdmin = $actor->isAdmin();

        if (! $isCurrentProvider && ! $isAdmin) {
            throw ValidationException::withMessages([
                'actor' => ['Only the current treating optometrist or an administrator can transfer this consultation.'],
            ]);
        }

        // Target must be a different active optometrist
        if ($newOptometrist->id === $encounter->optometrist_id) {
            throw ValidationException::withMessages([
                'new_optometrist_id' => ['The consultation is already assigned to this optometrist.'],
            ]);
        }

        if (! $newOptometrist->is_active || ! $newOptometrist->isOptometrist()) {
            throw ValidationException::withMessages([
                'new_optometrist_id' => ['The selected user is not an active optometrist.'],
            ]);
        }

        return DB::transaction(function () use ($encounter, $actor, $newOptometrist, $reason): Encounter {
            $lockedEncounter = Encounter::query()
                ->whereKey($encounter->id)
                ->lockForUpdate()
                ->first();

            if ($lockedEncounter->status !== EncounterStatus::InProgress) {
                throw ValidationException::withMessages([
                    'encounter' => ['This consultation is no longer in progress.'],
                ]);
            }

            $previousOptometristId = $lockedEncounter->optometrist_id;

            // Update encounter provider
            $lockedEncounter->update([
                'optometrist_id' => $newOptometrist->id,
            ]);

            // Synchronize appointment provider
            if ($lockedEncounter->appointment !== null) {
                $lockedEncounter->appointment->update([
                    'optometrist_id' => $newOptometrist->id,
                ]);
            }

            // Audit with allowlisted metadata only (no clinical text)
            app(CreateAuditLog::class)->handle(
                subject: $lockedEncounter,
                action: AuditEvent::EncounterTransferred->value,
                metadata: [
                    'appointment_id' => $lockedEncounter->appointment_id,
                    'previous_optometrist_id' => $previousOptometristId,
                    'new_optometrist_id' => $newOptometrist->id,
                    'reason' => $reason->value,
                    'actor_id' => $actor->id,
                ],
                actorId: $actor->id,
            );

            return $lockedEncounter->fresh(['appointment', 'optometrist']);
        });
    }
}
