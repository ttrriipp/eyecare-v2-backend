<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Filament\Resources\Billings\BillingResource;
use App\Filament\Resources\Billings\Widgets\BillingStatsWidget;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBillings extends ListRecords
{
    protected static string $resource = BillingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            BillingStatsWidget::class,
        ];
    }

    public function getTabs(): array
    {
        $statuses = ['issued', 'partially_paid', 'paid', 'voided'];

        $tabs = ['all' => Tab::make('All')];

        foreach ($statuses as $status) {
            $tabs[$status] = Tab::make(ucwords(str_replace('_', ' ', $status)))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                    'status',
                    fn (Builder $q) => $q->where('name', $status)
                ));
        }

        return $tabs;
    }
}
