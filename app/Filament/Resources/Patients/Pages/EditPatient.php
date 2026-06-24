<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditPatient extends EditRecord
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bill_service')
                ->label('Bill Service')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->url(fn (): string => ServiceRecordResource::getUrl('create', [
                    'customer_id' => $this->getRecord()->id,
                    'staff_id' => auth()->id(),
                ])),
        ];
    }
}
