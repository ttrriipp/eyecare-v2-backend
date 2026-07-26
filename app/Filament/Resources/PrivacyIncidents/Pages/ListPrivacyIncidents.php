<?php

namespace App\Filament\Resources\PrivacyIncidents\Pages;

use App\Filament\Resources\PrivacyIncidents\PrivacyIncidentResource;
use Filament\Resources\Pages\ListRecords;

class ListPrivacyIncidents extends ListRecords
{
    protected static string $resource = PrivacyIncidentResource::class;
}
