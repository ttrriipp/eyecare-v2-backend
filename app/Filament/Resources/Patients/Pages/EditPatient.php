<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Actions\PatientAccounts\IssuePatientInvitation;
use App\Actions\PatientAccounts\UnlinkPatientAccount;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\PatientInvitation;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditPatient extends EditRecord
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendInvitation')
                ->label('Send App Invitation')
                ->icon('heroicon-o-envelope')
                ->color('primary')
                ->visible(function (): bool {
                    $patient = $this->getRecord();

                    // Can only invite if patient is active and not already linked
                    if ($patient->user_id !== null) {
                        return false;
                    }

                    // Check for existing pending invitation
                    $hasPending = PatientInvitation::where('patient_id', $patient->id)
                        ->where('status', 'pending')
                        ->exists();

                    return ! $hasPending;
                })
                ->schema([
                    Select::make('channel')
                        ->label('Send via')
                        ->options([
                            'email' => 'Email',
                            'phone' => 'Phone',
                        ])
                        ->required()
                        ->default('email'),
                ])
                ->action(function (array $data): void {
                    $patient = $this->getRecord();
                    $channel = $data['channel'];

                    // Verify patient has the selected contact
                    $destination = $channel === 'email' ? $patient->contact_email : $patient->phone;

                    if (empty($destination)) {
                        Notification::make()
                            ->title("Patient does not have a {$channel} on record")
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $invitation = app(IssuePatientInvitation::class)->handle(
                            patient: $patient,
                            channel: $channel,
                            sender: auth()->user(),
                        );

                        Notification::make()
                            ->title("Invitation sent via {$channel}")
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot send invitation.';
                        Notification::make()
                            ->title('Cannot send invitation')
                            ->body($message)
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('unlinkAccount')
                ->label('Unlink Account')
                ->icon('heroicon-o-link-slash')
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->user_id !== null && auth()->user()->isAdmin())
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
                            patient: $this->getRecord(),
                            admin: auth()->user(),
                            reason: $data['reason'],
                        );

                        $this->record->refresh();
                        Notification::make()
                            ->title('Account unlinked successfully')
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot unlink account.';
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
