<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Actions\Prescriptions\FinalizePrescription;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Encounter;
use App\Models\Prescription;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;

class CreatePrescription extends CreateRecord
{
    protected static string $resource = PrescriptionResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public int $encounter;

    public function mount(): void
    {
        $encounterRecord = $this->getEncounter();
        $previousPrescription = $this->getPreviousPrescription();

        abort_unless(
            auth()->user()?->hasOptometristCapability() === true
                && $this->canCreateForEncounter($encounterRecord, $previousPrescription),
            403,
        );

        parent::mount();

        $this->form->fill([
            ...$this->getCopiedPrescriptionData($previousPrescription),
            'patient_id' => $encounterRecord->patient_id,
            'appointment_id' => $encounterRecord->appointment_id,
            'prescribed_at' => now()->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $encounter = $this->getEncounter();
        $author = auth()->user();

        abort_unless($author?->hasOptometristCapability() === true, 403);

        return app(FinalizePrescription::class)->handle(
            patient: $encounter->patient,
            encounter: $encounter,
            author: $author,
            data: Arr::only($data, [
                'main_od_value',
                'main_od_sphere',
                'main_od_cylinder',
                'main_os_value',
                'main_os_sphere',
                'main_os_cylinder',
                'add_od_value',
                'add_od_sphere',
                'add_od_cylinder',
                'add_os_value',
                'add_os_sphere',
                'add_os_cylinder',
                'remarks',
            ]),
            previousPrescription: $this->getPreviousPrescription(),
            amendmentReason: $data['amendment_reason'] ?? null,
        );
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Finalize Prescription');
    }

    protected function getRedirectUrl(): string
    {
        return EncounterResource::getUrl('edit', [
            'record' => $this->encounter,
        ]);
    }

    protected function getEncounter(): Encounter
    {
        return Encounter::query()
            ->with('patient')
            ->findOrFail($this->encounter);
    }

    protected function getPreviousPrescription(): ?Prescription
    {
        return null;
    }

    protected function canCreateForEncounter(
        Encounter $encounter,
        ?Prescription $previousPrescription,
    ): bool {
        return $previousPrescription === null
            && $encounter->status === EncounterStatus::InProgress
            && ! $encounter->prescriptions()->withTrashed()->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCopiedPrescriptionData(?Prescription $previousPrescription): array
    {
        if ($previousPrescription === null) {
            return [];
        }

        return Arr::only($previousPrescription->attributesToArray(), [
            'main_od_value',
            'main_od_sphere',
            'main_od_cylinder',
            'main_os_value',
            'main_os_sphere',
            'main_os_cylinder',
            'add_od_value',
            'add_od_sphere',
            'add_od_cylinder',
            'add_os_value',
            'add_os_sphere',
            'add_os_cylinder',
            'remarks',
        ]);
    }
}
