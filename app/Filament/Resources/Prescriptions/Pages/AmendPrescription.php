<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Enums\EncounterStatus;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Encounter;
use App\Models\Prescription;
use Filament\Actions\Action;
use Livewire\Attributes\Locked;

class AmendPrescription extends CreatePrescription
{
    protected static string $resource = PrescriptionResource::class;

    #[Locked]
    public int $previous;

    public function mount(): void
    {
        $previousPrescription = $this->getPreviousPrescription();

        abort_unless($previousPrescription->encounter_id !== null, 403);

        $this->encounter = $previousPrescription->encounter_id;

        parent::mount();
    }

    public function getTitle(): string
    {
        return 'Amend Prescription';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Finalize Amendment');
    }

    protected function getRedirectUrl(): string
    {
        return PrescriptionResource::getUrl('view', [
            'record' => $this->record,
        ]);
    }

    protected function getPreviousPrescription(): Prescription
    {
        return Prescription::query()
            ->findOrFail($this->previous);
    }

    protected function canCreateForEncounter(
        Encounter $encounter,
        ?Prescription $previousPrescription,
    ): bool {
        return $previousPrescription !== null
            && in_array($encounter->status, [EncounterStatus::InProgress, EncounterStatus::Completed], true)
            && $previousPrescription->encounter_id === $encounter->id
            && $previousPrescription->patient_id === $encounter->patient_id
            && ! Prescription::query()
                ->withTrashed()
                ->where('previous_prescription_id', $previousPrescription->id)
                ->exists();
    }
}
