<?php

namespace App\Filament\Resources\ServiceRecords\Pages;

use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceRecords extends ListRecords
{
    protected static string $resource = ServiceRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
