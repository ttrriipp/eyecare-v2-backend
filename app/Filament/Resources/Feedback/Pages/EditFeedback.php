<?php

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFeedback extends EditRecord
{
    protected static string $resource = FeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->label('Archive')->icon('heroicon-o-archive-box')->modalIcon('heroicon-o-archive-box')->modalHeading('Archive feedback')->modalDescription('This will hide the feedback from active lists. It can be restored later.')->modalSubmitActionLabel('Archive'),
        ];
    }
}
