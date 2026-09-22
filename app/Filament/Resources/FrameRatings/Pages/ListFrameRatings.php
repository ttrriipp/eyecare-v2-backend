<?php

namespace App\Filament\Resources\FrameRatings\Pages;

use App\Filament\Resources\FrameRatings\FrameRatingResource;
use App\Filament\Resources\FrameRatings\Widgets\FrameRatingStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListFrameRatings extends ListRecords
{
    protected static string $resource = FrameRatingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [FrameRatingStatsWidget::class];
    }
}
