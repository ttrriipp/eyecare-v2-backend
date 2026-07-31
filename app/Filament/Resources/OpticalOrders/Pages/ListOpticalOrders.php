<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOpticalOrders extends ListRecords
{
    protected static string $resource = OpticalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
