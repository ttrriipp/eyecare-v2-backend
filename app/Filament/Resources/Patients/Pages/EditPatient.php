<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Actions\Conversations\AssociateAccountConversation;
use App\Actions\PatientAccounts\IssuePatientInvitation;
use App\Actions\PatientAccounts\UnlinkPatientAccount;
use App\Enums\PatientInvitationStatus;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Patient;
use App\Models\PatientInvitation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditPatient extends EditRecord
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createQuotation')
                ->label('Create Quotation')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('success')
                ->visible(fn (): bool => auth()->user()?->hasPanelRole() === true)
                ->url(fn (): string => QuotationResource::getUrl('create', [
                    'patient' => $this->getRecord()->id,
                ])),

            Action::make('sendInvitation')
                ->label('Send App Invitation')
                ->icon('heroicon-o-envelope')
                ->color('primary')
                ->visible(function (): bool {
                    $patient = $this->getRecord();

                    if ($patient->user_id !== null) {
                        return false;
                    }

                    $hasPending = PatientInvitation::where('patient_id', $patient->id)
                        ->where('status', 'pending')
                        ->exists();

                    return ! $hasPending;
                })
                ->requiresConfirmation()
                ->modalDescription('This will text an invitation code to the phone number on file for this patient.')
                ->action(function (): void {
                    $patient = $this->getRecord();

                    if (empty($patient->phone)) {
                        Notification::make()
                            ->title('Patient does not have a phone number on record')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        app(IssuePatientInvitation::class)->handle(
                            patient: $patient,
                            channel: 'phone',
                            sender: auth()->user(),
                        );

                        Notification::make()
                            ->title('Invitation sent via phone')
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

            Action::make('revokeInvitation')
                ->label('Revoke Invitation')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->visible(function (): bool {
                    $patient = $this->getRecord();

                    if ($patient->user_id !== null) {
                        return false;
                    }

                    return PatientInvitation::where('patient_id', $patient->id)
                        ->where('status', 'pending')
                        ->exists();
                })
                ->requiresConfirmation()
                ->action(function (): void {
                    $patient = $this->getRecord();

                    PatientInvitation::where('patient_id', $patient->id)
                        ->where('status', 'pending')
                        ->update([
                            'status' => PatientInvitationStatus::Revoked,
                            'revoked_at' => now(),
                        ]);

                    Notification::make()
                        ->title('Invitation revoked')
                        ->success()
                        ->send();
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

            Action::make('linkAccount')
                ->label('Link Account')
                ->icon('heroicon-o-link')
                ->color('success')
                ->visible(function (): bool {
                    $patient = $this->getRecord();

                    // Can only link if patient is not already linked
                    if ($patient->user_id !== null) {
                        return false;
                    }

                    return auth()->user()->isAdmin();
                })
                ->requiresConfirmation()
                ->schema([
                    Select::make('user_id')
                        ->label('Select Account')
                        ->options(function () {
                            // Get unlinked patient-role users
                            $linkedUserIds = Patient::whereNotNull('user_id')
                                ->pluck('user_id')
                                ->toArray();

                            return User::whereHas('roles', fn ($q) => $q->where('name', 'patient'))
                                ->whereNotIn('id', $linkedUserIds)
                                ->get()
                                ->mapWithKeys(function ($user): array {
                                    $name = ($user->first_name && $user->last_name)
                                        ? "{$user->first_name} {$user->last_name}"
                                        : ($user->full_name ?: "User #{$user->id}");

                                    $label = $user->email ? "{$name} ({$user->email})" : $name;

                                    return [$user->id => $label];
                                })
                                ->toArray();
                        })
                        ->searchable()
                        ->required()
                        ->helperText('Only unlinked patient accounts are shown.'),
                ])
                ->action(function (array $data): void {
                    $patient = $this->getRecord();
                    $user = User::findOrFail($data['user_id']);

                    // Verify account is still unlinked
                    if ($user->patient !== null) {
                        Notification::make()
                            ->title('This account is already linked to another patient')
                            ->danger()
                            ->send();

                        return;
                    }

                    // Verify patient is still unlinked
                    if ($patient->fresh()->user_id !== null) {
                        Notification::make()
                            ->title('This patient is already linked to an account')
                            ->danger()
                            ->send();

                        return;
                    }

                    // Activate the link
                    DB::transaction(function () use ($patient, $user): void {
                        $patient->update(['user_id' => $user->id]);
                        app(AssociateAccountConversation::class)->handle($user, $patient);
                    });

                    $this->record->refresh();

                    Notification::make()
                        ->title('Account linked successfully')
                        ->success()
                        ->send();
                }),
        ];
    }
}
