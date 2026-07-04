<?php

namespace App\Filament\Resources\LensCategories\Pages;

use App\Filament\Resources\LensCategories\LensCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLensCategories extends ListRecords
{
    protected static string $resource = LensCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
