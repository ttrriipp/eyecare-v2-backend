<?php

namespace App\Filament\Resources\PatientAccounts\Pages;

use App\Actions\PatientAccounts\UnlinkPatientAccount;
use App\Filament\Resources\PatientAccounts\PatientAccountResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewPatientAccount extends ViewRecord
{
    protected static string $resource = PatientAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('unlinkAccount')
                ->label('Unlink Account')
                ->icon('heroicon-o-link-slash')
                ->color('danger')
                ->visible(fn () => $this->record->patient !== null && auth()->user()->isAdmin())
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason for unlinking')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        app(UnlinkPatientAccount::class)->handle(
                            patient: $this->record->patient,
                            admin: auth()->user(),
                            reason: $data['reason'],
                        );

                        $this->record->refresh();
                        Notification::make()
                            ->title('Account unlinked successfully')
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot unlink.';
                        Notification::make()
                            ->title('Cannot unlink')
                            ->body($message)
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
