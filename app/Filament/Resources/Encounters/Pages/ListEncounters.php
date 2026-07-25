<?php

namespace App\Filament\Resources\Encounters\Pages;

use App\Filament\Resources\Encounters\EncounterResource;
use Filament\Resources\Pages\ListRecords;

class ListEncounters extends ListRecords
{
    protected static string $resource = EncounterResource::class;
}
