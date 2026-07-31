<?php

namespace App\Filament\Resources\PatientLinkRequests\Pages;

use App\Filament\Resources\PatientLinkRequests\PatientLinkRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPatientLinkRequests extends ListRecords
{
    protected static string $resource = PatientLinkRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
