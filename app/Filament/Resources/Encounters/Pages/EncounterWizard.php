<?php

namespace App\Filament\Resources\Encounters\Pages;

use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Encounters\Schemas\EncounterWizardForm;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;

class EncounterWizard extends EditRecord
{
    use HasWizard;

    protected static string $resource = EncounterResource::class;

    protected string $view = 'filament.resources.encounters.pages.encounter-wizard';

    public function getTitle(): string
    {
        return 'Encounter Wizard — '.$this->record->encounter_number;
    }

    public function getSteps(): array
    {
        return EncounterWizardForm::steps();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
