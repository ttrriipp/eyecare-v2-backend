<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Quotation'),
        ];
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

            'accepted' => Tab::make('Accepted')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', QuotationStatus::Accepted)),

            'declined' => Tab::make('Declined')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', QuotationStatus::Declined)),
        ];
    }
}
