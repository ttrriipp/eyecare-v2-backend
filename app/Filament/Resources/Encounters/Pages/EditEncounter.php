<?php

namespace App\Filament\Resources\Encounters\Pages;

use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditEncounter extends EditRecord
{
    protected static string $resource = EncounterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startEncounter')
                ->label('Start Encounter')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Waiting
                    && auth()->user()?->hasOptometristCapability() === true)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status' => EncounterStatus::InProgress,
                        'started_at' => now(),
                    ]);
                    $this->refreshFormData(['status', 'started_at']);
                }),

            Action::make('completeEncounter')
                ->label('Complete Encounter')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::InProgress
                    && auth()->user()?->hasOptometristCapability() === true)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status' => EncounterStatus::Completed,
                        'completed_at' => now(),
                    ]);
                    $this->refreshFormData(['status', 'completed_at']);
                }),
        ];
    }
}
