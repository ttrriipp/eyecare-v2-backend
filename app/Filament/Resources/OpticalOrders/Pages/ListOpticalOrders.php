<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOpticalOrders extends ListRecords
{
    protected static string $resource = OpticalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'drafts' => Tab::make('Drafts')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', QuotationStatus::Draft)),

            'awaiting' => Tab::make('Awaiting Decision')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', QuotationStatus::Presented)
                    ->whereDoesntHave('jobOrder')),

            'confirmed' => Tab::make('Confirmed')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('jobOrder', fn (Builder $q) => $q->where('status', JobOrderStatus::Queued))),

            'production' => Tab::make('In Production')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('jobOrder', fn (Builder $q) => $q->where('status', JobOrderStatus::InProgress))),

            'ready' => Tab::make('Ready for Pickup')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('jobOrder', fn (Builder $q) => $q->where('status', JobOrderStatus::ReadyForDispensing))),

            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('jobOrder', fn (Builder $q) => $q->where('status', JobOrderStatus::Dispensed))),
        ];
    }
}
