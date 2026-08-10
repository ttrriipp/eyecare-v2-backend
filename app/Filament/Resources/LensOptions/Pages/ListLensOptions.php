<?php

namespace App\Filament\Resources\LensOptions\Pages;

use App\Filament\Resources\LensOptions\LensOptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLensOptions extends ListRecords
{
    protected static string $resource = LensOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
