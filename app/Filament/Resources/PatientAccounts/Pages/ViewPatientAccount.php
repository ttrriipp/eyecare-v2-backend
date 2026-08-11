<?php

namespace App\Filament\Resources\PatientAccounts\Pages;

use App\Actions\Conversations\AssociateAccountConversation;
use App\Actions\PatientAccounts\UnlinkPatientAccount;
use App\Filament\Resources\PatientAccounts\PatientAccountResource;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ViewPatientAccount extends ViewRecord
{
    protected static string $resource = PatientAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('linkPatientRecord')
                ->label('Link Patient Record')
                ->icon('heroicon-o-link')
                ->color('success')
                ->visible(fn () => $this->record->patient === null && auth()->user()->isAdmin())
                ->requiresConfirmation()
                ->schema([
                    Select::make('patient_id')
                        ->label('Select Patient')
                        ->options(function () {
                            return Patient::whereNull('user_id')
                                ->orderBy('first_name')
                                ->get()
                                ->mapWithKeys(fn ($p) => [
                                    $p->id => "{$p->full_name} ({$p->patient_number})",
                                ])
                                ->toArray();
                        })
                        ->searchable()
                        ->required()
                        ->helperText('Only unlinked patients are shown.'),
                ])
                ->action(function (array $data): void {
                    $patient = Patient::findOrFail($data['patient_id']);

                    // Verify patient is still unlinked
                    if ($patient->user_id !== null) {
                        Notification::make()
                            ->title('This patient is already linked to another account')
                            ->danger()
                            ->send();

                        return;
                    }

                    // Verify account is still unlinked
                    if ($this->record->patient !== null) {
                        Notification::make()
                            ->title('This account is already linked to a patient')
                            ->danger()
                            ->send();

                        return;
                    }

                    // Activate the link
                    DB::transaction(function () use ($patient): void {
                        $patient->update(['user_id' => $this->record->id]);
                        app(AssociateAccountConversation::class)->handle($this->record, $patient);
                    });

                    // Revoke tokens to force re-authentication with link
                    $this->record->tokens()->delete();

                    $this->record->refresh();

                    Notification::make()
                        ->title("Linked to {$patient->full_name} ({$patient->patient_number})")
                        ->success()
                        ->send();
                }),

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
