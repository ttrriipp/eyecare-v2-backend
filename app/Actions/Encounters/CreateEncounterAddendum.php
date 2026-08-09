<?php

namespace App\Actions\Encounters;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterAddendumType;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\EncounterAddendum;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEncounterAddendum
{
    private const int MAX_REASON_LENGTH = 1_000;

    private const int MAX_CONTENT_LENGTH = 10_000;

    public function handle(
        Encounter $encounter,
        User $actor,
        EncounterAddendumType $type,
        string $reason,
        string $content,
    ): EncounterAddendum {
        if ($encounter->status !== EncounterStatus::Completed) {
            throw ValidationException::withMessages([
                'encounter' => ['Addenda can only be added to completed encounters.'],
            ]);
        }

        if (! $actor->is_active) {
            throw ValidationException::withMessages([
                'actor' => ['Inactive accounts cannot create addenda.'],
            ]);
        }

        if (! $actor->isOptometrist()) {
            throw ValidationException::withMessages([
                'actor' => ['Only optometrists can create encounter addenda.'],
            ]);
        }

        // Type-specific authorization
        if ($type === EncounterAddendumType::Correction) {
            if ($encounter->completed_by !== $actor->id) {
                throw ValidationException::withMessages([
                    'actor' => ['Only the original completing optometrist can create a correction.'],
                ]);
            }
        }

        // Trim and validate
        $reason = trim($reason);
        $content = trim($content);

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'reason' => ['The reason must not exceed '.self::MAX_REASON_LENGTH.' characters.'],
            ]);
        }

        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw ValidationException::withMessages([
                'content' => ['The content must not exceed '.self::MAX_CONTENT_LENGTH.' characters.'],
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => ['The reason is required.'],
            ]);
        }

        if (blank($content)) {
            throw ValidationException::withMessages([
                'content' => ['The content is required.'],
            ]);
        }

        return DB::transaction(function () use ($encounter, $actor, $type, $reason, $content): EncounterAddendum {
            // Lock the encounter to prevent concurrent sequence allocation
            $lockedEncounter = Encounter::query()
                ->whereKey($encounter->id)
                ->lockForUpdate()
                ->first();

            if ($lockedEncounter->status !== EncounterStatus::Completed) {
                throw ValidationException::withMessages([
                    'encounter' => ['This encounter is no longer completed.'],
                ]);
            }

            // Allocate next sequence number
            $nextSequence = ($lockedEncounter->addenda()->max('sequence_number') ?? 0) + 1;

            $addendum = EncounterAddendum::create([
                'encounter_id' => $lockedEncounter->id,
                'sequence_number' => $nextSequence,
                'type' => $type,
                'reason' => $reason,
                'content' => $content,
                'authored_by' => $actor->id,
                'authored_at' => now(),
            ]);

            // Audit
            app(CreateAuditLog::class)->handle(
                subject: $addendum,
                action: AuditEvent::EncounterAmended->value,
                metadata: [
                    'encounter_id' => $lockedEncounter->id,
                    'addendum_id' => $addendum->id,
                    'type' => $type->value,
                    'actor_id' => $actor->id,
                ],
            );

            return $addendum->fresh(['author']);
        });
    }
}
