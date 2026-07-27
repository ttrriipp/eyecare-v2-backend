<?php

namespace App\Filament\Resources\Encounters\Pages;

use App\Actions\Encounters\CompleteEncounter;
use App\Actions\Encounters\StartEncounter;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

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
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Planned
                    && auth()->user()?->is_optometrist === true)
                ->requiresConfirmation()
                ->schema(fn (): array => [
                    Select::make('optometrist_id')
                        ->label('Optometrist')
                        ->options(fn () => User::query()->optometrists()->orderBy('name')->pluck('name', 'id'))
                        ->default(auth()->id())
                        ->required()
                        ->searchable()
                        ->preload(),
                ])
                ->action(function (array $data): void {
                    try {
                        $optometrist = User::query()->findOrFail($data['optometrist_id']);

                        app(StartEncounter::class)->handle(
                            encounter: $this->record,
                            optometrist: $optometrist,
                            actor: auth()->user(),
                        );

                        Notification::make()->title('Encounter started')->success()->send();
                        $this->refreshFormData(['status', 'started_at', 'optometrist_id']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot start encounter')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('completeEncounter')
                ->label('Complete Encounter')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::InProgress
                    && auth()->user()?->is_optometrist === true)
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(CompleteEncounter::class)->handle(
                            encounter: $this->record,
                            actor: auth()->user(),
                        );

                        Notification::make()->title('Encounter completed')->success()->send();
                        $this->refreshFormData(['status', 'completed_at']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot complete encounter')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
