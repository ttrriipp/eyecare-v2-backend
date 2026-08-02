<?php

namespace App\Filament\Resources\Encounters\Pages;

use App\Actions\Encounters\CompleteEncounter;
use App\Actions\Encounters\StartEncounter;
use App\Actions\Prescriptions\FinalizePrescription;
use App\Actions\Quotations\CreateQuotation;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Schemas\QuotationCreationForm;
use App\Models\Quotation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditEncounter extends EditRecord
{
    protected static string $resource = EncounterResource::class;

    /**
     * @var array<int, string>
     */
    private const PRESCRIPTION_FIELDS = [
        'main_od_value',
        'main_od_sphere',
        'main_od_cylinder',
        'main_os_value',
        'main_os_sphere',
        'main_os_cylinder',
        'add_od_value',
        'add_od_sphere',
        'add_od_cylinder',
        'add_os_value',
        'add_os_sphere',
        'add_os_cylinder',
        'remarks',
    ];

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $prescription = $this->record->prescriptions()->latest('id')->first();

        $data['prescription'] = $prescription === null
            ? ['prescribed_at' => now()->toDateString()]
            : Arr::only($prescription->attributesToArray(), [
                ...self::PRESCRIPTION_FIELDS,
                'prescribed_at',
            ]);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $prescriptionData = $data['prescription'] ?? [];
        unset($data['prescription']);

        $shouldFinalizePrescription = $record->status === EncounterStatus::InProgress
            && $this->shouldFinalizePrescription($prescriptionData)
            && ! $record->prescriptions()->withTrashed()->exists();
        $author = $shouldFinalizePrescription ? auth()->user() : null;

        if ($shouldFinalizePrescription) {
            abort_unless($author instanceof User && $author->hasOptometristCapability(), 403);
        }

        $record->update($data);

        if ($shouldFinalizePrescription && $author instanceof User) {
            app(FinalizePrescription::class)->handle(
                patient: $record->patient,
                encounter: $record,
                author: $author,
                data: Arr::only($prescriptionData, self::PRESCRIPTION_FIELDS),
            );
        }

        // Complete the encounter when wizard is submitted for in-progress encounters
        if ($record->status === EncounterStatus::InProgress) {
            $actor = auth()->user();

            if ($actor instanceof User) {
                app(CompleteEncounter::class)->handle(
                    encounter: $record,
                    actor: $actor,
                );
            }
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $prescriptionData
     */
    private function shouldFinalizePrescription(array $prescriptionData): bool
    {
        return collect(self::PRESCRIPTION_FIELDS)
            ->contains(fn (string $field): bool => filled($prescriptionData[$field] ?? null));
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

            Action::make('viewAppointment')
                ->label('View Appointment')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible(fn (): bool => $this->record->appointment !== null)
                ->url(fn (): string => AppointmentResource::getUrl('edit', [
                    'record' => $this->record->appointment,
                ])),
        ];
    }
}
