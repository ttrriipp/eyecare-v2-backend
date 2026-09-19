<?php

namespace App\Filament\Resources\PatientAccounts\Pages;

use App\Actions\PatientAccounts\LinkPatientAccount;
use App\Actions\PatientAccounts\PatientAccountIdentityMatcher;
use App\Actions\PatientAccounts\UnlinkPatientAccount;
use App\Exceptions\PatientIdentityMismatchException;
use App\Filament\Resources\PatientAccounts\PatientAccountResource;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewPatientAccount extends ViewRecord
{
    protected static string $resource = PatientAccountResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();
        $patientName = $record->patient?->full_name ?? $record->full_name ?? 'Unknown patient';

        return 'View Account for '.$patientName;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('linkPatientRecord')
                ->label('Link Patient Record')
                ->icon('heroicon-o-link')
                ->color('success')
                ->visible(fn () => $this->record->patient === null && auth()->user()->isAdmin())
                ->authorize('linkPatientRecord')
                ->requiresConfirmation()
                ->schema([
                    Select::make('patient_id')
                        ->label('Select Patient')
                        ->options(function (): array {
                            $matcher = app(PatientAccountIdentityMatcher::class);

                            return Patient::whereNull('user_id')
                                ->get()
                                ->filter(fn (Patient $patient): bool => $matcher->handle($this->record, $patient)->isEligible())
                                ->sortBy('first_name')
                                ->mapWithKeys(fn ($p) => [
                                    $p->id => "{$p->full_name} ({$p->patient_number})",
                                ])
                                ->toArray();
                        })
                        ->searchable()
                        ->required()
                        ->helperText('Only unlinked patient records matching this account are shown.'),
                ])
                ->action(function (array $data): void {
                    $patient = Patient::findOrFail($data['patient_id']);

                    try {
                        app(LinkPatientAccount::class)->handle(
                            account: $this->record,
                            patient: $patient,
                            source: 'patient_account',
                            sourceId: $this->record->id,
                            actorId: auth()->id(),
                        );
                    } catch (PatientIdentityMismatchException|ValidationException $exception) {
                        $message = $exception instanceof ValidationException
                            ? collect($exception->errors())->flatten()->first()
                            : 'Account details do not match this patient record.';
                        Notification::make()
                            ->title('Cannot link patient record')
                            ->body($message ?? 'Cannot link patient record.')
                            ->danger()
                            ->send();

                        return;
                    }

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
                ->authorize('unlinkPatientRecord')
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
