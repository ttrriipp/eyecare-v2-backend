<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Enums\JobOrderStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\OpticalOrders\Widgets\OpticalOrderStatsWidget;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOpticalOrders extends ListRecords
{
    protected static string $resource = OpticalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newDirectOrder')
                ->label('New Direct Order')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(OpticalOrderResource::getUrl('create')),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'confirmed' => Tab::make('Confirmed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::Queued)),

            'production' => Tab::make('Processing')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::InProgress)),

            'ready' => Tab::make('Ready for Pickup')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::ReadyForDispensing)),

            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::Dispensed)),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::Cancelled)),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OpticalOrderStatsWidget::class,
        ];
    }
}
