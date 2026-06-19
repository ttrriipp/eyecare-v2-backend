<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Widgets\OrderStatsWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New order'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OrderStatsWidget::class,
        ];
    }

    public function getTabs(): array
    {
        $orderedStatuses = [
            'requested',
            'under_review',
            'confirmed',
            'preparing',
            'ready_for_pickup',
            'completed',
            'cancelled',
        ];

        $tabs = ['all' => Tab::make('All')];

        foreach ($orderedStatuses as $status) {
            $label = ucwords(str_replace('_', ' ', $status));
            $tabs[$status] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                    'status',
                    fn (Builder $q) => $q->where('name', $status)
                ));
        }

        return $tabs;
    }
}
