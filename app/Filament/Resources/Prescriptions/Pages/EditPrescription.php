<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Services\PdfService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrescription extends EditRecord
{
    protected static string $resource = PrescriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print_prescription')
                ->label('Print Prescription')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn () => app(PdfService::class)->prescriptionPrintout($this->getRecord())),

            Action::make('print_card')
                ->label('Print Card')
                ->icon('heroicon-o-credit-card')
                ->color('gray')
                ->tooltip('Wallet-size prescription card')
                ->action(fn () => app(PdfService::class)->prescriptionCard($this->getRecord())),

            DeleteAction::make()->visible(fn () => auth()->user()?->isAdmin() ?? false),
        ];
    }
}
