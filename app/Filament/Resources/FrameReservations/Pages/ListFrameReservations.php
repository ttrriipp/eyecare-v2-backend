<?php

namespace App\Filament\Resources\FrameReservations\Pages;

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
            'requests' => Tab::make('Requests')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('accepted_at')),

            'accepted' => Tab::make('Accepted')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('accepted_at')),
        ];
    }
}
