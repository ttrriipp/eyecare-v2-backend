<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Filament\Resources\Billings\BillingResource;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewBilling extends ViewRecord
{
    protected static string $resource = BillingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_order')
                ->label('View Order')
                ->icon('heroicon-o-shopping-bag')
                ->color('gray')
                ->url(fn () => OrderResource::getUrl('edit', ['record' => $this->getRecord()->order_id])),
        ];
    }
}
