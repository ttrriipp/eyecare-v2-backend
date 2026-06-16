<?php

namespace App\Filament\Resources\VisitReasons\Pages;

use App\Filament\Resources\VisitReasons\VisitReasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVisitReasons extends ListRecords
{
    protected static string $resource = VisitReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
