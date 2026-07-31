<?php

namespace App\Filament\Resources\PatientLinkRequests\Pages;

use App\Actions\PatientAccounts\ReviewPatientLinkRequest;
use App\Filament\Resources\PatientLinkRequests\PatientLinkRequestResource;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewPatientLinkRequest extends ViewRecord
{
    protected static string $resource = PatientLinkRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'pending')
                ->schema([
                    Select::make('patient_id')
                        ->label('Link to Patient')
                        ->options(function () {
                            return Patient::whereNull('user_id')
                                ->get()
                                ->mapWithKeys(fn ($p) => [
                                    $p->id => "{$p->full_name} ({$p->patient_number})",
                                ])
                                ->toArray();
                        })
                        ->searchable()
                        ->required()
                        ->helperText('Select the patient to link this account to.'),
                    Textarea::make('note')
                        ->label('Decision Note')
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    try {
                        $patient = Patient::findOrFail($data['patient_id']);

                        app(ReviewPatientLinkRequest::class)->approve(
                            linkRequest: $this->record,
                            patient: $patient,
                            reviewer: auth()->user(),
                            note: $data['note'] ?? null,
                        );

                        $this->record->refresh();
                        Notification::make()
                            ->title('Link request approved')
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot approve.';
                        Notification::make()
                            ->title('Cannot approve')
                            ->body($message)
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === 'pending')
                ->schema([
                    Textarea::make('note')
                        ->label('Rejection Reason')
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    try {
                        app(ReviewPatientLinkRequest::class)->reject(
                            linkRequest: $this->record,
                            reviewer: auth()->user(),
                            note: $data['note'],
                        );

                        $this->record->refresh();
                        Notification::make()
                            ->title('Link request rejected')
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot reject.';
                        Notification::make()
                            ->title('Cannot reject')
                            ->body($message)
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
