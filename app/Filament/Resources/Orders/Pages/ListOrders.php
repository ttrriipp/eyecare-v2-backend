<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Widgets\OrderStatsWidget;
use Filament\Actions\Action;
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
            Action::make('walk_in_sale')
                ->label('Walk-in Sale')
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->url(fn (): string => OrderResource::getUrl('create', ['walkin' => 'true'])),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OrderStatsWidget::class,
        ];
    }

    public function updatedActiveTab(): void
    {
        $this->dispatch('order-tab-changed', tab: $this->activeTab);
    }

    public function getTabs(): array
    {
        $orderedStatuses = [
            'requested',
            'confirmed',
            'processing',
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
