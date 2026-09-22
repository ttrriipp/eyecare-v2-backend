<?php

namespace App\Filament\Resources\VisitRatings\Pages;

use App\Filament\Resources\VisitRatings\VisitRatingResource;
use App\Filament\Resources\VisitRatings\Widgets\VisitRatingStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListVisitRatings extends ListRecords
{
    protected static string $resource = VisitRatingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [VisitRatingStatsWidget::class];
    }
}
