<?php

namespace App\Filament\Resources\LensTypes\Pages;

use App\Filament\Resources\LensTypes\LensTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLensTypes extends ListRecords
{
    protected static string $resource = LensTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
