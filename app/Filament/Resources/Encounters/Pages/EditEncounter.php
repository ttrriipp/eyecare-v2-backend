<?php

namespace App\Filament\Resources\Encounters\Pages;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Encounters\CompleteEncounter;
use App\Actions\Encounters\StartEncounter;
use App\Actions\Quotations\CreateQuotation;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Schemas\QuotationCreationForm;
use App\Models\Quotation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditEncounter extends EditRecord
{
    protected static string $resource = EncounterResource::class;

    public function getTitle(): string
    {
        $status = $this->record->status;

        if ($status === EncounterStatus::Planned) {
            return 'Waiting — '.$this->record->encounter_number;
        }

        return "Edit {$this->record->encounter_number}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin/encounters' => 'Encounters',
            $this->record->encounter_number,
            'Edit',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startEncounter')
                ->label('Start Visit')
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

            Action::make('assignOptometrist')
                ->label('Assign Optometrist')
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Planned)
                ->schema(fn (): array => [
                    Select::make('optometrist_id')
                        ->label('Optometrist')
                        ->options(fn () => User::query()->optometrists()->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->preload(),
                ])
                ->action(function (array $data): void {
                    $this->record->update(['optometrist_id' => $data['optometrist_id']]);
                    Notification::make()->title('Optometrist assigned')->success()->send();
                    $this->refreshFormData(['optometrist_id']);
                }),

            Action::make('createPrescription')
                ->label('Create Prescription')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('primary')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::InProgress
                    && auth()->user()?->hasOptometristCapability() === true
                    && ! $this->record->prescriptions()->withTrashed()->exists())
                ->url(fn (): string => PrescriptionResource::getUrl('create', [
                    'encounter' => $this->record->id,
                ])),

            Action::make('viewPrescription')
                ->label('View Prescription')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (): bool => $this->record->prescriptions()->exists())
                ->url(fn (): string => PrescriptionResource::getUrl('view', [
                    'record' => $this->record->prescriptions()->latest('id')->value('id'),
                ])),

            Action::make('createQuotation')
                ->label('Create Quotation')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, [EncounterStatus::InProgress, EncounterStatus::Completed], true)
                    && in_array(auth()->user()?->role?->name, ['admin', 'staff'], true)
                    && $this->record->prescriptions()
                        ->whereDoesntHave('nextPrescription')
                        ->exists()
                    && ! Quotation::query()
                        ->withTrashed()
                        ->where('encounter_id', $this->record->id)
                        ->exists())
                ->modalHeading('Create Draft Quotation')
                ->modalDescription('Add the items and estimated prices discussed with the patient.')
                ->modalSubmitActionLabel('Create Draft')
                ->modalWidth('7xl')
                ->schema(QuotationCreationForm::components())
                ->action(function (array $data): void {
                    $creator = auth()->user();

                    abort_unless($creator instanceof User, 403);

                    $quotation = app(CreateQuotation::class)->handle(
                        encounter: $this->record,
                        creator: $creator,
                        data: $data,
                    );

                    Notification::make()
                        ->title('Draft quotation created')
                        ->body('Review the quotation, then present it to the patient.')
                        ->success()
                        ->send();

                    $this->redirect(QuotationResource::getUrl('edit', [
                        'record' => $quotation,
                    ]));
                }),

            Action::make('viewQuotation')
                ->label('View Quotation')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('gray')
                ->visible(fn (): bool => Quotation::query()
                    ->where('encounter_id', $this->record->id)
                    ->exists())
                ->url(fn (): string => QuotationResource::getUrl('edit', [
                    'record' => Quotation::query()
                        ->where('encounter_id', $this->record->id)
                        ->latest('id')
                        ->value('id'),
                ])),

            Action::make('cancelAppointment')
                ->label('Cancel Appointment')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Planned
                    && $this->record->appointment !== null
                    && in_array($this->record->appointment->status?->name, ['scheduled', 'checked_in'], true))
                ->requiresConfirmation()
                ->schema(fn (): array => [
                    Select::make('reason_category')
                        ->label('Reason')
                        ->options([
                            'patient_requested' => 'Patient requested',
                            'optometrist_unavailable' => 'Optometrist unavailable',
                            'clinic_schedule_change' => 'Clinic schedule change',
                            'scheduling_conflict' => 'Scheduling conflict',
                            'other' => 'Other',
                        ])
                        ->required(),
                    Textarea::make('reason_details')
                        ->label('Details')
                        ->requiredIf('reason_category', 'other')
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(CancelAppointment::class)->handle(
                            appointment: $this->record->appointment,
                            initiator: 'clinic',
                            actor: auth()->user(),
                            reasonCategory: $data['reason_category'],
                            reasonDetails: $data['reason_details'] ?? null,
                        );

                        Notification::make()->title('Appointment cancelled')->success()->send();
                        $this->refreshFormData(['status']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot cancel')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('completeEncounter')
                ->label('Complete Visit')
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
