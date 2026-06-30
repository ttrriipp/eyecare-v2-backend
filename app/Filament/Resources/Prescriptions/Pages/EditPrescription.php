<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
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
                ->url(fn () => route('pdf.prescription', $this->getRecord()))
                ->openUrlInNewTab(),

            Action::make('print_card')
                ->label('Print Card')
                ->icon('heroicon-o-credit-card')
                ->color('gray')
                ->tooltip('Wallet-size prescription card')
                ->url(fn () => route('pdf.prescription.card', $this->getRecord()))
                ->openUrlInNewTab(),

            DeleteAction::make()->visible(fn () => auth()->user()?->isAdmin() ?? false),
        ];
    }
}
