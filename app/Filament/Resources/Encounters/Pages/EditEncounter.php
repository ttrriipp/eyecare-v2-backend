<?php

namespace App\Filament\Resources\Encounters\Pages;

use App\Actions\BillingRecords\AddChargesToBilling;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Actions\Encounters\AssignEncounterOptometrist;
use App\Actions\Encounters\CompleteEncounter;
use App\Actions\Encounters\CreateEncounterAddendum;
use App\Actions\Encounters\SaveEncounterDraft;
use App\Actions\Encounters\StartEncounter;
use App\Actions\Encounters\TransferEncounter;
use App\Actions\Encounters\VoidEncounter;
use App\Actions\Prescriptions\FinalizePrescription;
use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Enums\EncounterAddendumType;
use App\Enums\EncounterStatus;
use App\Enums\EncounterTransferReason;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\BillingRecords\Schemas\ServiceChargeForm;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\BillingRecord;
use App\Models\Quotation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
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
     * Others can view but the form fields will be disabled.
     */
    public function authorizeAccess(): void
    {
        parent::authorizeAccess();
    }

    /**
     * Check if the current user is the assigned optometrist for this encounter.
     */
    public function isAssignedOptometrist(): bool
    {
        $user = auth()->user();

        return $user->isOptometrist() && $this->record->optometrist_id === $user->id;
    }

    /**
     * Check if the current user can edit this encounter's clinical contents.
     */
    public function canEditClinicalContents(): bool
    {
        if ($this->record->status !== EncounterStatus::InProgress) {
            return true; // Planned encounters can be edited by authorized users
        }

        return $this->isAssignedOptometrist();
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
            // ── Planned encounter: primary action ──
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

            Action::make('assignOptometrist')
                ->label('Assign Optometrist')
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->visible(fn (): bool => match (true) {
                    $this->record->status !== EncounterStatus::Planned => false,
                    default => auth()->user()->isAdmin()
                        || auth()->user()->isStaff()
                        || auth()->user()->isOptometrist(),
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
                    try {
                        app(AssignEncounterOptometrist::class)->handle(
                            encounter: $this->record,
                            actor: auth()->user(),
                            optometrist: User::query()->findOrFail($data['optometrist_id']),
                        );

                        Notification::make()->title('Optometrist assigned')->success()->send();
                        $this->refreshFormData(['optometrist_id']);
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot assign optometrist.';
                        Notification::make()->title('Cannot assign optometrist')->body($message)->danger()->send();
                    }
                }),

            // ── Completed encounter: primary action ──
            Action::make('createQuotation')
                ->label('Create Quotation')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Completed
                    && (
                        auth()->user()?->isAdmin() === true
                        || auth()->user()?->isStaff() === true
                        || auth()->user()?->isOptometrist() === true
                    )
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

            // ── Planned: reassignment (admin/staff only) ──
            Action::make('reassignOptometrist')
                ->label('Reassign Optometrist')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Planned
                    && (auth()->user()->isAdmin() || auth()->user()->isStaff()))
                ->schema(fn (): array => [
                    Select::make('optometrist_id')
                        ->label('Optometrist')
                        ->options(fn () => User::query()->optometrists()->orderBy('first_name')->orderBy('last_name')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name]))
                        ->required()
                        ->searchable()
                        ->preload(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(AssignEncounterOptometrist::class)->handle(
                            encounter: $this->record,
                            actor: auth()->user(),
                            optometrist: User::query()->findOrFail($data['optometrist_id']),
                        );
                        Notification::make()->title('Optometrist reassigned')->success()->send();
                        $this->refreshFormData(['optometrist_id']);
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot reassign.';
                        Notification::make()->title('Cannot reassign')->body($message)->danger()->send();
                    }
                }),

            // ── In-progress: transfer encounter ──
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
                ->modalDescription('Transfer this encounter to another optometrist.')
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

            // ── Completed: print ──
            Action::make('printEncounter')
                ->label('Print Encounter')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Completed)
                ->url(fn (): string => route('encounters.print', ['encounter' => $this->record->id]))
                ->openUrlInNewTab(),

            // ── Completed: add addendum (correction or supplement) ──
            Action::make('addAddendum')
                ->label('Add Addendum')
                ->icon('heroicon-o-document-plus')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Completed
                    && auth()->user()?->isOptometrist())
                ->requiresConfirmation()
                ->modalHeading('Add Addendum')
                ->modalDescription('Append a note to this completed encounter. The original record remains unchanged.')
                ->modalSubmitActionLabel('Add Addendum')
                ->schema([
                    Select::make('type')
                        ->label('Type')
                        ->options(fn (): array => $this->record->completed_by === auth()->id()
                            ? ['correction' => 'Correction', 'supplement' => 'Supplement']
                            : ['supplement' => 'Supplement'])
                        ->default(fn (): string => $this->record->completed_by === auth()->id() ? 'correction' : 'supplement')
                        ->helperText(fn (Get $get): string => $get('type') === 'correction'
                            ? 'Fixes an error in the original record.'
                            : 'Adds new information without changing the original.')
                        ->live()
                        ->required(),
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
                    $type = EncounterAddendumType::from($data['type']);

                    try {
                        app(CreateEncounterAddendum::class)->handle(
                            encounter: $this->record,
                            actor: auth()->user(),
                            type: $type,
                            reason: $data['reason'],
                            content: $data['content'],
                        );

                        Notification::make()
                            ->title($type === EncounterAddendumType::Correction ? 'Correction added' : 'Supplement added')
                            ->success()
                            ->send();
                        $this->refreshFormData([]);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot add addendum')->body($e->getMessage())->danger()->send();
                    }
                }),

            // ── Completed: add service charge ──
            Action::make('addCharge')
                ->label(fn (): string => $this->latestBillingRecord()?->status !== null
                    && in_array($this->latestBillingRecord()->status, [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid], true)
                    ? 'Add Another Service Charge'
                    : 'Add Service Charge')
                ->icon('heroicon-o-plus-circle')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === EncounterStatus::Completed)
                ->modalHeading('Add Service Charge')
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Add to Billing')
                ->schema([
                    ServiceChargeForm::items(),
                    ServiceChargeForm::total(),
                ])
                ->action(function (array $data): void {
                    try {
                        $encounter = $this->record;
                        $items = ServiceChargeForm::normalizeItems($data['items'] ?? [])
                            ->map(fn (array $item): array => [
                                ...$item,
                                'encounter_id' => $encounter->id,
                            ])
                            ->values();

                        $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                            patient: $encounter->patient,
                            encounter: $encounter,
                        );

                        $billingRecord = app(AddChargesToBilling::class)->handle(
                            billingRecord: $billingRecord,
                            sourceKind: BillingItemSourceKind::Encounter,
                            items: $items,
                        );

                        Notification::make()
                            ->title('Charge added')
                            ->body("Billing Record: {$billingRecord->billing_record_number}")
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot add charge')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('viewBillingRecord')
                ->label('View Billing Record')
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->visible(fn (): bool => $this->latestBillingRecord() !== null)
                ->url(fn (): string => BillingRecordResource::getUrl('edit', [
                    'record' => $this->latestBillingRecord(),
                ])),

            // ── Void encounter ──
            Action::make('voidEncounter')
                ->label('Void Encounter')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, [EncounterStatus::Planned, EncounterStatus::Completed], true)
                    && auth()->user()?->can('void', $this->record) === true)
                ->requiresConfirmation()
                ->modalHeading('Void Encounter')
                ->modalDescription('This will mark the encounter as voided. This action cannot be undone.')
                ->modalSubmitActionLabel('Void Encounter')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason for Voiding')
                        ->required()
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    try {
                        app(VoidEncounter::class)->handle(
                            encounter: $this->record,
                            actor: auth()->user(),
                            reason: $data['reason'],
                        );

                        Notification::make()->title('Encounter voided')->success()->send();
                        $this->refreshFormData(['status']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot void encounter')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
