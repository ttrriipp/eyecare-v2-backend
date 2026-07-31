<?php

namespace App\Filament\Resources\PatientAccounts\Pages;

use App\Filament\Resources\PatientAccounts\PatientAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPatientAccounts extends ListRecords
{
    protected static string $resource = PatientAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
