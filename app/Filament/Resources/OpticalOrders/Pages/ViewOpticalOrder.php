<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Actions\OpticalOrders\AcceptAndStartOpticalOrder;
use App\Enums\QuotationStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewOpticalOrder extends ViewRecord
{
    protected static string $resource = OpticalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('acceptAndStart')
                ->label('Accept & Start Order')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => $this->record->status === QuotationStatus::Presented)
                ->requiresConfirmation()
                ->action(function () {
                    $result = app(AcceptAndStartOpticalOrder::class)->handle($this->record);

                    $this->record->refresh();
                    $this->notify('success', "Order started. Job Order: {$result['job_order']->job_order_number}");
                }),
        ];
    }
}
