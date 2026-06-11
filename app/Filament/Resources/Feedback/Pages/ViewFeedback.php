<?php

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Models\Feedback;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewFeedback extends ViewRecord
{
    protected static string $resource = FeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Reply')
                ->schema([
                    Textarea::make('staff_reply')
                        ->label('Reply')
                        ->required()
                        ->maxLength(2000)
                        ->rows(4),
                ])
                ->action(function (array $data): void {
                    /** @var Feedback $record */
                    $record = $this->getRecord();
                    $record->update([
                        'staff_reply' => $data['staff_reply'],
                        'replied_by' => Auth::id(),
                        'replied_at' => now(),
                    ]);
                })
                ->successNotificationTitle('Reply saved'),
        ];
    }
}
