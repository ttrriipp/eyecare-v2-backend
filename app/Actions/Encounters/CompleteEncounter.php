<?php

namespace App\Actions\Encounters;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Prescriptions\FinalizePrescription;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\AppointmentStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteEncounter
{
    /**
     * @var array<int, string>
     */
    private const array REQUIRED_FIELDS = [
        'chief_complaint',
        'findings',
        'assessment',
        'plan',
    ];

    /**
     * @param  array<string, mixed>|null  $prescriptionData
     */
    public function handle(
        Encounter $encounter,
        User $actor,
        ?array $prescriptionData = null,
    ): Encounter {
        if ($encounter->status !== EncounterStatus::InProgress) {
            throw ValidationException::withMessages([
                'encounter' => ['Only in-progress encounters can be completed.'],
            ]);
        }

        if (! $actor->is_active) {
            throw ValidationException::withMessages([
                'actor' => ['Inactive accounts cannot complete encounters.'],
            ]);
        }

        if (! $actor->isOptometrist()) {
            throw ValidationException::withMessages([
                'actor' => ['Only an optometrist can complete an encounter.'],
            ]);
        }

        if ($encounter->optometrist_id !== $actor->id) {
            throw ValidationException::withMessages([
                'actor' => ['Only the assigned optometrist can complete this encounter.'],
            ]);
        }

        // Validate required fields
        $this->validateRequiredFields($encounter);

        return DB::transaction(function () use ($encounter, $actor, $prescriptionData): Encounter {
            // Lock and revalidate
            $lockedEncounter = Encounter::query()
                ->whereKey($encounter->id)
                ->lockForUpdate()
                ->first();

            if ($lockedEncounter->status !== EncounterStatus::InProgress) {
                throw ValidationException::withMessages([
                    'encounter' => ['This encounter has already been processed.'],
                ]);
            }

            // Recheck assignment after lock
            if ($lockedEncounter->optometrist_id !== $actor->id) {
                throw ValidationException::withMessages([
                    'actor' => ['The encounter has been reassigned to another optometrist.'],
                ]);
            }

            // Finalize prescription if draft data exists
            if ($prescriptionData !== null && $this->hasPrescriptionData($prescriptionData)) {
                // Only if no prescription already exists
                if (! $lockedEncounter->prescriptions()->withTrashed()->exists()) {
                    app(FinalizePrescription::class)->handle(
                        patient: $lockedEncounter->patient,
                        encounter: $lockedEncounter,
                        author: $actor,
                        data: $prescriptionData,
                    );

                    // Clear the draft
                    $lockedEncounter->update(['prescription_draft' => null]);
                }
            }

            // Complete the encounter
            $lockedEncounter->update([
                'status' => EncounterStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->id,
            ]);

            // Fulfill the appointment
            $appointment = $lockedEncounter->appointment;
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
                subject: $lockedEncounter,
                action: AuditEvent::EncounterCompleted->value,
                metadata: [
                    'appointment_id' => $lockedEncounter->appointment_id,
                    'optometrist_id' => $lockedEncounter->optometrist_id,
                    'actor_id' => $actor->id,
                ],
                actorId: $actor->id,
            );

            return $lockedEncounter->fresh(['appointment', 'optometrist']);
        });
    }

    private function validateRequiredFields(Encounter $encounter): void
    {
        $missing = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (blank($encounter->$field)) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'encounter' => ['The following fields are required to complete the visit: '.implode(', ', $missing)],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasPrescriptionData(array $data): bool
    {
        $fields = [
            'main_od_value', 'main_od_sphere', 'main_od_cylinder',
            'main_os_value', 'main_os_sphere', 'main_os_cylinder',
            'add_od_value', 'add_od_sphere', 'add_od_cylinder',
            'add_os_value', 'add_os_sphere', 'add_os_cylinder',
            'remarks',
        ];

        return collect($fields)
            ->contains(fn (string $field): bool => filled($data[$field] ?? null));
    }
}
