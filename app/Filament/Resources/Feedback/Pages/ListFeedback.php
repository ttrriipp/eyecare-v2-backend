<?php

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Feedback\Widgets\FeedbackStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListFeedback extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            FeedbackStatsWidget::class,
        ];
    }
}
