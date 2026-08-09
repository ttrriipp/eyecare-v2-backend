<?php

namespace App\Actions\Encounters;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveEncounterDraft
{
    private const int MAX_NARRATIVE_LENGTH = 10_000;

    private const array CLINICAL_FIELDS = [
        'chief_complaint',
        'past_ocular_history',
        'past_surgical_history',
        'past_medical_history',
        'allergies',
        'medications',
        'findings',
        'supporting_test_results',
        'remarks',
        'assessment',
        'plan',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(
        Encounter $encounter,
        User $actor,
        array $data,
        int $lastWizardStep,
    ): Encounter {
        if ($encounter->status !== EncounterStatus::InProgress) {
            throw ValidationException::withMessages([
                'encounter' => ['Only in-progress encounters can have drafts saved.'],
            ]);
        }

        if (! $actor->is_active) {
            throw ValidationException::withMessages([
                'actor' => ['Inactive accounts cannot save encounter drafts.'],
            ]);
        }

        if (! $actor->isOptometrist()) {
            throw ValidationException::withMessages([
                'actor' => ['Only an optometrist can save encounter drafts.'],
            ]);
        }

        if ($encounter->optometrist_id !== $actor->id) {
            throw ValidationException::withMessages([
                'actor' => ['Only the assigned optometrist can save this encounter draft.'],
            ]);
        }

        if ($lastWizardStep < 1 || $lastWizardStep > 4) {
            throw ValidationException::withMessages([
                'last_wizard_step' => ['The wizard step must be between 1 and 4.'],
            ]);
        }

        // Trim and validate narrative fields
        $cleaned = $this->cleanData($data);

        return DB::transaction(function () use ($encounter, $cleaned, $lastWizardStep): Encounter {
            $encounter->update(array_merge($cleaned, [
                'last_wizard_step' => $lastWizardStep,
                'draft_saved_at' => now(),
            ]));

            return $encounter->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cleanData(array $data): array
    {
        $cleaned = [];

        foreach (self::CLINICAL_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($value === null) {
                $cleaned[$field] = null;

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if (mb_strlen($value) > self::MAX_NARRATIVE_LENGTH) {
                throw ValidationException::withMessages([
                    $field => ["The {$field} must not exceed ".self::MAX_NARRATIVE_LENGTH.' characters.'],
                ]);
            }

            $cleaned[$field] = $value === '' ? null : $value;
        }

        return $cleaned;
    }
}
