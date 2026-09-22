<?php

namespace App\Filament\Resources\ClinicPaymentMethods\Pages;

use App\Filament\Resources\ClinicPaymentMethods\ClinicPaymentMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClinicPaymentMethods extends ListRecords
{
    protected static string $resource = ClinicPaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
