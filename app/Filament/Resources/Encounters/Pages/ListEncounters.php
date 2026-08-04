<?php

namespace App\Filament\Resources\Encounters\Pages;

use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Widgets\EncounterStatsWidget;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEncounters extends ListRecords
{
    protected static string $resource = EncounterResource::class;

    protected function getHeaderWidgets(): array
    {
        return [EncounterStatsWidget::class];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'planned' => Tab::make('Planned')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', EncounterStatus::Planned)),

            'in_progress' => Tab::make('In Progress')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', EncounterStatus::InProgress)),

            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', EncounterStatus::Completed)),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', EncounterStatus::Cancelled)),
        ];
    }
}
