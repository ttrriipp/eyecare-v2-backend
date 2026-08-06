<?php

namespace App\Filament\Resources\FrameReservations\Pages;

use App\Enums\ReservationStatus;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFrameReservations extends ListRecords
{
    protected static string $resource = FrameReservationResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'requested' => Tab::make('Requested')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReservationStatus::Requested)),

            'prepared' => Tab::make('Prepared')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReservationStatus::Prepared)),

            'tried_on' => Tab::make('Tried On')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReservationStatus::TriedOn)),

            'converted' => Tab::make('Converted')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReservationStatus::Converted)),

            'released' => Tab::make('Released')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReservationStatus::Released)),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReservationStatus::Cancelled)),
        ];
    }
}
