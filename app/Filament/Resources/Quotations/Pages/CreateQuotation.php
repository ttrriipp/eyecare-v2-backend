<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Actions\Quotations\CreateQuotation as CreateQuotationAction;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Schemas\QuotationCreationForm;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    public ?int $encounterId = null;

    public ?int $patientId = null;

    public ?int $prescriptionId = null;

    public function mount(
        ?string $encounter = null,
        ?string $patient = null,
        ?string $prescription = null,
    ): void {
        $encounter ??= request()->query('encounter');
        $patient ??= request()->query('patient');
        $prescription ??= request()->query('prescription');

        $this->encounterId = filled($encounter) ? (int) $encounter : null;
        $this->patientId = filled($patient) ? (int) $patient : null;
        $this->prescriptionId = filled($prescription) ? (int) $prescription : null;

        parent::mount();
    }

    public function getTitle(): string
    {
        $patient = $this->resolveEncounter()?->patient
            ?? $this->resolvePrescription()?->patient
            ?? $this->resolvePatient();

        return $patient !== null
            ? "New Quotation for {$patient->full_name}"
            : 'New Quotation';
    }

    public function form(Schema $schema): Schema
    {
        $encounter = $this->resolveEncounter();
        $contextPrescription = $this->resolvePrescription();
        $patient = $encounter?->patient ?? $contextPrescription?->patient ?? $this->resolvePatient();
        $encounterPrescription = $encounter !== null ? $this->resolveEncounterPrescription($encounter) : null;
        $prescriptionResolver = fn (Get $get): ?Prescription => $contextPrescription
            ?? $encounterPrescription
            ?? (filled($get('prescription_id'))
                ? Prescription::query()->with('author')->find((int) $get('prescription_id'))
                : null);

        return $schema
            ->columns(1)
            ->components([
                Section::make('Patient & Prescription')
                    ->schema([
                        ...($patient !== null
                            ? [
                                Placeholder::make('patient_display')
                                    ->label('Patient')
                                    ->content("{$patient->full_name} ({$patient->patient_number})"),
                            ]
                            : [
                                Select::make('patient_id')
                                    ->label('Patient')
                                    ->options(fn (): array => Patient::query()
                                        ->get()
                                        ->mapWithKeys(fn (Patient $p): array => [$p->id => "{$p->full_name} ({$p->patient_number})"])
                                        ->all())
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live(),
                            ]),
                        ...(match (true) {
                            $contextPrescription !== null => [
                                Placeholder::make('prescription_display')
                                    ->label('Prescription')
                                    ->content($contextPrescription->prescription_number),
                            ],
                            $encounter !== null => [
                                Placeholder::make('prescription_display')
                                    ->label('Prescription')
                                    ->content($encounterPrescription?->prescription_number
                                        ?? 'No finalized prescription on this encounter yet.'),
                            ],
                            default => [
                                Select::make('prescription_id')
                                    ->label('Prescription')
                                    ->options(function (Get $get) use ($patient): array {
                                        $patientId = $patient?->id ?? $get('patient_id');

                                        if (blank($patientId)) {
                                            return [];
                                        }

                                        return Prescription::query()
                                            ->where('patient_id', $patientId)
                                            ->whereDoesntHave('nextPrescription')
                                            ->get()
                                            ->mapWithKeys(fn (Prescription $p): array => [$p->id => $p->prescription_number])
                                            ->all();
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->live(),
                            ],
                        }),
                        Placeholder::make('no_prescription_notice')
                            ->hiddenLabel()
                            ->content('No spectacle prescription linked. You may quote frames, contact lenses, accessories, custom products, and services.')
                            ->visible(fn (Get $get): bool => $prescriptionResolver($get) === null)
                            ->columnSpanFull(),
                        Placeholder::make('prescription_prescribed_at')
                            ->label('Prescribed')
                            ->content(fn (Get $get): string => $prescriptionResolver($get)?->prescribed_at?->format('M j, Y') ?? '—')
                            ->visible(fn (Get $get): bool => $prescriptionResolver($get) !== null),
                        Placeholder::make('prescription_author')
                            ->label('Prescriber')
                            ->content(fn (Get $get): string => $prescriptionResolver($get)?->author?->full_name ?? '—')
                            ->visible(fn (Get $get): bool => $prescriptionResolver($get) !== null),
                        Placeholder::make('prescription_status')
                            ->label('Version')
                            ->content(fn (Get $get): string => match (true) {
                                $prescriptionResolver($get) === null => '—',
                                $prescriptionResolver($get)->isVoided() => 'Voided',
                                $prescriptionResolver($get)->isCurrentVersion() => 'Current',
                                default => 'Superseded',
                            })
                            ->badge()
                            ->color(fn (Get $get): string => match (true) {
                                $prescriptionResolver($get)?->isVoided() === true => 'danger',
                                $prescriptionResolver($get)?->isCurrentVersion() === true => 'success',
                                default => 'warning',
                            })
                            ->visible(fn (Get $get): bool => $prescriptionResolver($get) !== null),
                        Placeholder::make('view_prescription')
                            ->label('Prescription')
                            ->content('View Rx')
                            ->url(fn (Get $get): ?string => $prescriptionResolver($get) !== null
                                ? PrescriptionResource::getUrl('view', ['record' => $prescriptionResolver($get)])
                                : null)
                            ->visible(fn (Get $get): bool => $prescriptionResolver($get) !== null),
                    ])
                    ->columns(2),

                ...QuotationCreationForm::components(
                    patientIdResolver: fn (Get $get): ?int => $patient?->id
                        ?? (filled($get('patient_id')) ? (int) $get('patient_id') : null),
                ),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $creator = auth()->user();

        abort_unless($creator instanceof User, 403);

        $encounter = $this->resolveEncounter();
        $prescription = $this->resolvePrescription()
            ?? (filled($data['prescription_id'] ?? null) ? Prescription::query()->find($data['prescription_id']) : null);
        $patient = $encounter?->patient
            ?? $prescription?->patient
            ?? $this->resolvePatient()
            ?? Patient::query()->find($data['patient_id'] ?? null);

        unset($data['patient_id'], $data['prescription_id']);

        if ($patient === null) {
            Notification::make()
                ->title('Cannot create quotation')
                ->body('A patient is required.')
                ->danger()
                ->send();

            $this->halt();
        }

        try {
            $quotation = app(CreateQuotationAction::class)->handle(
                patient: $patient,
                creator: $creator,
                data: $data,
                encounter: $encounter,
                prescription: $prescription,
            );

            return $quotation;
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Cannot create quotation')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return QuotationResource::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Quotation saved as draft';
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('saveDraft')
                ->label('Save Draft')
                ->icon('heroicon-o-document')
                ->color('primary')
                ->action(function (): void {
                    $this->create();
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->url(QuotationResource::getUrl('index'))
                ->cancelParentActions(),
        ];
    }

    protected function hasCreateAnother(): bool
    {
        return false;
    }

    private function resolveEncounter(): ?Encounter
    {
        return $this->encounterId !== null ? Encounter::query()->find($this->encounterId) : null;
    }

    private function resolvePatient(): ?Patient
    {
        return $this->patientId !== null ? Patient::query()->find($this->patientId) : null;
    }

    private function resolvePrescription(): ?Prescription
    {
        return $this->prescriptionId !== null ? Prescription::query()->find($this->prescriptionId) : null;
    }

    private function resolveEncounterPrescription(Encounter $encounter): ?Prescription
    {
        return $encounter->prescriptions()
            ->whereDoesntHave('nextPrescription')
            ->latest('id')
            ->first();
    }
}
