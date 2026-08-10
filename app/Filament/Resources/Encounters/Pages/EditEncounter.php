<?php

namespace App\Filament\Resources\Encounters\Pages;

use App\Actions\BillingRecords\AddEncounterChargesToBilling;
use App\Actions\Encounters\CompleteEncounter;
use App\Actions\Encounters\CreateEncounterAddendum;
use App\Actions\Encounters\SaveEncounterDraft;
use App\Actions\Encounters\StartEncounter;
use App\Actions\Encounters\TransferEncounter;
use App\Actions\Prescriptions\FinalizePrescription;
use App\Enums\BillingRecordStatus;
use App\Enums\EncounterAddendumType;
use App\Enums\EncounterStatus;
use App\Enums\EncounterTransferReason;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditEncounter extends EditRecord
{
    protected static string $resource = EncounterResource::class;

    private bool $isCompletingVisit = false;

    public bool $isSavingDraft = false;

    /**
     * Only the assigned optometrist can edit an in-progress encounter.
     * Admin and staff can view but not edit clinical contents.
     */
    public function authorizeAccess(): void
    {
        parent::authorizeAccess();

        $user = auth()->user();

        if ($this->record->status === EncounterStatus::InProgress) {
            // Only the assigned optometrist can edit clinical contents
            if (! $user->isOptometrist() || $this->record->optometrist_id !== $user->id) {
                abort(403, 'Only the assigned optometrist can edit this encounter.');
            }
        }
    }

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
     * Resume at the last saved wizard step.
     */
    protected function getSavedStep(): int
    {
        return $this->record->last_wizard_step ?? 1;
    }

    /**
     * Save a partial draft via the domain action.
     */
    public function saveDraft(int $lastWizardStep): void
    {
        // Use $this->data which already contains form state without re-validation
        $data = $this->data;

        $prescriptionData = $data['prescription'] ?? [];
        unset($data['prescription']);

        try {
            // Save clinical narrative fields via domain action
            app(SaveEncounterDraft::class)->handle(
                encounter: $this->record,
                actor: auth()->user(),
                data: $data,
                lastWizardStep: $lastWizardStep,
            );

            // Save prescription draft separately (JSON field, not in clinical narrative)
            $hasPrescriptionData = collect(self::PRESCRIPTION_FIELDS)
                ->contains(fn (string $field): bool => filled($prescriptionData[$field] ?? null));

            $this->record->update([
                'prescription_draft' => $hasPrescriptionData ? $prescriptionData : null,
            ]);

            // Refresh the record to pick up saved data
            $this->record->refresh();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Cannot save draft')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $prescription = $this->record->prescriptions()->latest('id')->first();

        if ($prescription !== null) {
            // Use finalized prescription data
            $data['prescription'] = Arr::only($prescription->attributesToArray(), [
                ...self::PRESCRIPTION_FIELDS,
                'prescribed_at',
            ]);
        } elseif ($this->record->prescription_draft !== null) {
            // Use saved draft data
            $data['prescription'] = $this->record->prescription_draft;
        } else {
            // No prescription or draft yet
            $data['prescription'] = ['prescribed_at' => now()->toDateString()];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $prescriptionData = $data['prescription'] ?? [];
        unset($data['prescription']);

        // Only process prescription data when explicitly saving a draft or completing the visit
        if ($this->isSavingDraft) {
            // Save prescription draft
            $hasPrescriptionData = collect(self::PRESCRIPTION_FIELDS)
                ->contains(fn (string $field): bool => filled($prescriptionData[$field] ?? null));

            $data['prescription_draft'] = $hasPrescriptionData ? $prescriptionData : null;
            $data['draft_saved_at'] = now();
        } elseif ($this->isCompletingVisit) {
            // Finalize prescription when completing the visit
            $shouldFinalizePrescription = $record->status === EncounterStatus::InProgress
                && $this->shouldFinalizePrescription($prescriptionData)
                && ! $record->prescriptions()->withTrashed()->exists();

            if ($shouldFinalizePrescription) {
                $author = auth()->user();
                abort_unless($author instanceof User && $author->isOptometrist(), 403);

                $record->update($data);

                app(FinalizePrescription::class)->handle(
                    patient: $record->patient,
                    encounter: $record,
                    author: $author,
                    data: Arr::only($prescriptionData, self::PRESCRIPTION_FIELDS),
                );

                $record->update(['prescription_draft' => null]);

                app(CompleteEncounter::class)->handle(
                    encounter: $record,
                    actor: $author,
                );

                $this->isSavingDraft = false;

                return $record;
            }
        }

        $record->update($data);

        if ($this->isCompletingVisit && $record->status === EncounterStatus::InProgress) {
            $actor = auth()->user();

            if ($actor instanceof User) {
                app(CompleteEncounter::class)->handle(
                    encounter: $record,
                    actor: $actor,
                );
            }
        }

        $this->isSavingDraft = false;

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

    private function latestBillingRecord(): ?BillingRecord
    {
        return BillingRecord::query()
            ->where('encounter_id', $this->record->id)
            ->whereNull('deleted_at')
            ->latest('id')
            ->first();
    }

    protected function getFormActions(): array
    {
        // Hide save/cancel buttons - wizard has its own Complete Visit button
        // and planned/completed encounters are read-only
        return [];
    }

    protected function completeVisitAction(): Action
    {
        return Action::make('completeVisit')
            ->label('Complete Visit')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => $this->record->status === EncounterStatus::InProgress)
            ->requiresConfirmation()
            ->modalHeading('Complete Visit')
            ->modalDescription('Are you sure you want to complete this visit? This action cannot be undone.')
            ->modalSubmitActionLabel('Complete Visit')
            ->action(function (): void {
                try {
                    $this->isCompletingVisit = true;
                    $this->save(
                        shouldRedirect: false,
                        shouldSendSavedNotification: false,
                    );

                    Notification::make()->title('Visit completed')->success()->send();
                    $this->redirect(EncounterResource::getUrl('edit', [
                        'record' => $this->record,
                    ]));
                } catch (ValidationException $e) {
                    Notification::make()->title('Cannot complete visit')->body($e->getMessage())->danger()->send();
                } finally {
                    $this->isCompletingVisit = false;
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewAppointment')
                ->label('View Appointment')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible(fn (): bool => $this->record->appointment !== null)
                ->url(fn (): string => AppointmentResource::getUrl('edit', [
                    'record' => $this->record->appointment,
                ])),

            Action::make('viewPrescription')
                ->label('View Prescription')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (): bool => $this->record->prescriptions()->exists())
                ->url(fn (): string => PrescriptionResource::getUrl('view', [
                    'record' => $this->record->prescriptions()->latest('id')->value('id'),
                ])),

            Action::make('viewBillingRecord')
                ->label('View Billing Record')
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->visible(fn (): bool => $this->latestBillingRecord() !== null)
                ->url(fn (): string => BillingRecordResource::getUrl('edit', [
                    'record' => $this->latestBillingRecord(),
                ])),

            Action::make('printEncounter')
                ->label('Print Encounter')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Completed)
                ->url(fn (): string => route('encounters.print', ['encounter' => $this->record->id]))
                ->openUrlInNewTab(),

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
                ->url(fn (): string => QuotationResource::getUrl('create', [
                    'encounter' => $this->record->id,
                ])),

            Action::make('viewOpticalOrder')
                ->label('View Optical Order')
                ->icon('heroicon-o-shopping-bag')
                ->color('gray')
                ->visible(fn (): bool => Quotation::query()
                    ->where('encounter_id', $this->record->id)
                    ->exists())
                ->url(function (): string {
                    $quotation = Quotation::query()
                        ->where('encounter_id', $this->record->id)
                        ->latest('id')
                        ->first();

                    if ($quotation?->jobOrder !== null) {
                        return OpticalOrderResource::getUrl('edit', ['record' => $quotation->jobOrder]);
                    }

                    return QuotationResource::getUrl('edit', ['record' => $quotation]);
                }),

            Action::make('startEncounter')
                ->label('Start Consultation')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Planned
                    && auth()->user()?->isOptometrist() === true
                    && ($this->record->optometrist_id === null || $this->record->optometrist_id === auth()->id()))
                ->requiresConfirmation()
                ->modalHeading('Start Consultation')
                ->modalDescription('You will become the treating optometrist for this encounter.')
                ->modalSubmitActionLabel('Start')
                ->action(function (): void {
                    try {
                        app(StartEncounter::class)->handle(
                            encounter: $this->record,
                            actor: auth()->user(),
                        );

                        Notification::make()->title('Encounter started')->success()->send();
                        $this->refreshFormData(['status', 'started_at', 'optometrist_id']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot start encounter')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('transferEncounter')
                ->label('Transfer Encounter')
                ->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::InProgress
                    && (
                        ($this->record->optometrist_id === auth()->id() && auth()->user()?->isOptometrist())
                        || auth()->user()?->isAdmin()
                    ))
                ->requiresConfirmation()
                ->modalHeading('Transfer Encounter')
                ->modalDescription('Transfer this encounter to another optometrist. The new optometrist will become the treating provider.')
                ->modalSubmitActionLabel('Transfer')
                ->schema([
                    Select::make('new_optometrist_id')
                        ->label('New Optometrist')
                        ->options(fn () => User::query()
                            ->optometrists()
                            ->where('id', '!=', $this->record->optometrist_id)
                            ->orderBy('first_name')
                            ->orderBy('last_name')
                            ->get()
                            ->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name]))
                        ->required()
                        ->searchable()
                        ->preload(),
                    Select::make('reason')
                        ->label('Reason')
                        ->options(collect(EncounterTransferReason::cases())->mapWithKeys(
                            fn (EncounterTransferReason $case): array => [$case->value => str($case->value)->replace('_', ' ')->title()],
                        ))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $newOptometrist = User::query()->findOrFail($data['new_optometrist_id']);
                        $reason = EncounterTransferReason::from($data['reason']);

                        app(TransferEncounter::class)->handle(
                            encounter: $this->record,
                            actor: auth()->user(),
                            newOptometrist: $newOptometrist,
                            reason: $reason,
                        );

                        Notification::make()->title('Encounter transferred')->success()->send();
                        $this->refreshFormData(['optometrist_id']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot transfer encounter')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('addCharge')
                ->label(fn (): string => $this->latestBillingRecord()?->status !== null
                    && in_array($this->latestBillingRecord()->status, [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid], true)
                    ? 'Add Another Service Charge'
                    : 'Add Service Charge')
                ->icon('heroicon-o-plus-circle')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Completed)
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('service_id')
                                ->label('Service')
                                ->options(fn (): array => Service::query()
                                    ->active()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (Service $service): array => [
                                        $service->id => "{$service->name} (₱".number_format((float) $service->price, 2).')',
                                    ])
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->live()
                                ->columnSpanFull()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    $service = Service::query()->find($state);

                                    if ($service === null) {
                                        return;
                                    }

                                    $set('description', $service->name);
                                    $set('unit_price', $service->price);
                                    $set('line_total', number_format(
                                        ((float) ($get('quantity') ?? 1)) * ((float) $service->price),
                                        2,
                                    ));
                                }),
                            TextInput::make('description')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),
                            Grid::make(3)
                                ->columnSpanFull()
                                ->schema([
                                    TextInput::make('quantity')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->default(1)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            $set('line_total', number_format(
                                                ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                                2,
                                            ));
                                        }),
                                    TextInput::make('unit_price')
                                        ->label('Unit Price')
                                        ->numeric()
                                        ->prefix('₱')
                                        ->minValue(0)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            $set('line_total', number_format(
                                                ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                                2,
                                            ));
                                        }),
                                    TextInput::make('line_total')
                                        ->label('Line Total')
                                        ->prefix('₱')
                                        ->disabled()
                                        ->dehydrated(false),
                                ]),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Add Service Line'),

                    Placeholder::make('total')
                        ->label('Total')
                        ->content(function (Get $get): string {
                            $total = collect($get('items') ?? [])->sum(
                                fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                                    * ((float) ($item['unit_price'] ?? 0)),
                            );

                            return '₱'.number_format($total, 2);
                        }),
                ])
                ->action(function (array $data): void {
                    try {
                        $billingRecord = app(AddEncounterChargesToBilling::class)->handle(
                            encounter: $this->record,
                            items: $data['items'],
                        );
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot add charge')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Charge added')
                        ->body("Billing Record: {$billingRecord->billing_record_number}")
                        ->success()
                        ->send();
                }),

            Action::make('assignOptometrist')
                ->label('Assign Optometrist')
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->visible(fn (): bool => match (true) {
                    // Admin/owner: may assign or reassign at any time
                    auth()->user()->isAdmin() => true,
                    // Staff: may assign or reassign before consultation starts
                    auth()->user()->isStaff() => $this->record->status === EncounterStatus::Planned,
                    // Optometrist: cannot reassign
                    default => false,
                })
                ->schema(fn (): array => [
                    Select::make('optometrist_id')
                        ->label('Optometrist')
                        ->options(fn () => User::query()->optometrists()->orderBy('first_name')->orderBy('last_name')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name]))
                        ->required()
                        ->searchable()
                        ->preload(),
                ])
                ->action(function (array $data): void {
                    $this->record->update(['optometrist_id' => $data['optometrist_id']]);

                    // Sync to linked appointment
                    if ($this->record->appointment !== null) {
                        $this->record->appointment->update(['optometrist_id' => $data['optometrist_id']]);
                    }

                    Notification::make()->title('Optometrist assigned')->success()->send();
                    $this->refreshFormData(['optometrist_id']);
                }),

            Action::make('addCorrection')
                ->label('Add Correction')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Completed
                    && $this->record->completed_by === auth()->id()
                    && auth()->user()?->isOptometrist())
                ->requiresConfirmation()
                ->modalHeading('Add Correction')
                ->modalDescription('This will append a correction note to the completed encounter. The original record will remain unchanged.')
                ->modalSubmitActionLabel('Add Correction')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason for Correction')
                        ->required()
                        ->maxLength(1000)
                        ->rows(2),
                    Textarea::make('content')
                        ->label('Correction Content')
                        ->required()
                        ->maxLength(10000)
                        ->rows(4),
                ])
                ->action(function (array $data): void {
                    try {
                        app(CreateEncounterAddendum::class)->handle(
                            encounter: $this->record,
                            actor: auth()->user(),
                            type: EncounterAddendumType::Correction,
                            reason: $data['reason'],
                            content: $data['content'],
                        );

                        Notification::make()->title('Correction added')->success()->send();
                        $this->refreshFormData([]);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot add correction')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('addSupplement')
                ->label('Add Supplement')
                ->icon('heroicon-o-plus-circle')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Completed
                    && auth()->user()?->isOptometrist()
                    && $this->record->completed_by !== auth()->id())
                ->requiresConfirmation()
                ->modalHeading('Add Supplement')
                ->modalDescription('This will append a supplementary note to the completed encounter. The original record will remain unchanged.')
                ->modalSubmitActionLabel('Add Supplement')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(1000)
                        ->rows(2),
                    Textarea::make('content')
                        ->label('Content')
                        ->required()
                        ->maxLength(10000)
                        ->rows(4),
                ])
                ->action(function (array $data): void {
                    try {
                        app(CreateEncounterAddendum::class)->handle(
                            encounter: $this->record,
                            actor: auth()->user(),
                            type: EncounterAddendumType::Supplement,
                            reason: $data['reason'],
                            content: $data['content'],
                        );

                        Notification::make()->title('Supplement added')->success()->send();
                        $this->refreshFormData([]);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot add supplement')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
