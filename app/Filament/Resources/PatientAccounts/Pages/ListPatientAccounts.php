<?php

namespace App\Filament\Resources\PatientAccounts\Pages;

use App\Filament\Resources\PatientAccounts\PatientAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListPatientAccounts extends ListRecords
{
    protected static string $resource = PatientAccountResource::class;
}
