<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Filament\Resources\Billings\BillingResource;
use App\Filament\Resources\Billings\Widgets\BillingStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListBillings extends ListRecords
{
    protected static string $resource = BillingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            BillingStatsWidget::class,
        ];
    }
}
