<?php

namespace App\Filament\Resources\AccessoryOrderRequests\Pages;

use App\Enums\AccessoryOrderRequestStatus;
use App\Filament\Resources\AccessoryOrderRequests\AccessoryOrderRequestResource;
use App\Filament\Resources\AccessoryOrderRequests\Widgets\AccessoryOrderRequestStatsWidget;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAccessoryOrderRequests extends ListRecords
{
    protected static string $resource = AccessoryOrderRequestResource::class;

    protected function getHeaderWidgets(): array
    {
        return [AccessoryOrderRequestStatsWidget::class];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AccessoryOrderRequestStatus::Pending)),
            'accepted' => Tab::make('Accepted')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AccessoryOrderRequestStatus::Accepted)),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AccessoryOrderRequestStatus::Rejected)),
        ];
    }
}
