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
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
        $prescriptionEyewearResolver = fn (Get $get): bool => (bool) (
            $get('include_prescription_eyewear')
            ?? $get('../../include_prescription_eyewear')
            ?? false
        );

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
                                    ->required(fn (Get $get): bool => (bool) $get('include_prescription_eyewear'))
                                    ->live(),
                            ],
                        }),
                        Toggle::make('include_prescription_eyewear')
                            ->label('Include prescription eyewear')
                            ->default($contextPrescription !== null || $encounterPrescription !== null)
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, ?bool $state): void {
                                $items = collect($get('items') ?? []);
                                $hasEnteredItem = $items
                                    ->contains(fn (array $item): bool => collect([
                                        $item['description'] ?? null,
                                        $item['unit_price'] ?? null,
                                        $item['product_variant_id'] ?? null,
                                        $item['lens_category_id'] ?? null,
                                        $item['lens_option_id'] ?? null,
                                        $item['service_id'] ?? null,
                                    ])->contains(fn (mixed $value): bool => filled($value)));

                                if ($state && ! $hasEnteredItem) {
                                    $set('items', []);
                                } elseif (! $state && ! $hasEnteredItem) {
                                    $set('items', [[
                                        'item_kind' => 'catalog',
                                        'quantity' => 1,
                                    ]]);
                                }
                            })
                            ->dehydrated(false)
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
                    prescriptionEyewearResolver: $prescriptionEyewearResolver,
                    dedicatedPrescriptionEyewear: true,
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
        $includePrescriptionEyewear = (bool) ($this->data['include_prescription_eyewear'] ?? false);
        $patient = $encounter?->patient
            ?? $prescription?->patient
            ?? $this->resolvePatient()
            ?? Patient::query()->find($data['patient_id'] ?? null);

        $data = $this->normalizePrescriptionEyewearData($data, $includePrescriptionEyewear);

        unset(
            $data['patient_id'],
            $data['prescription_id'],
            $data['include_prescription_eyewear'],
            $data['eyewear_frame_source'],
            $data['eyewear_frame_variant_id'],
            $data['eyewear_patient_frame_description'],
            $data['eyewear_patient_frame_price'],
            $data['eyewear_lens_category_id'],
            $data['eyewear_lens_options'],
        );

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
                includePrescriptionEyewear: $includePrescriptionEyewear,
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

    /**
     * Convert the dedicated eyewear form state into the existing quotation
     * item payload. The transient role markers are used only for validation
     * and are removed before quotation items are persisted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePrescriptionEyewearData(array $data, bool $includePrescriptionEyewear): array
    {
        if (! $includePrescriptionEyewear) {
            return $data;
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn (array $item): bool => collect([
                $item['description'] ?? null,
                $item['unit_price'] ?? null,
                $item['product_variant_id'] ?? null,
                $item['lens_category_id'] ?? null,
                $item['lens_option_id'] ?? null,
                $item['service_id'] ?? null,
            ])->contains(fn (mixed $value): bool => filled($value)))
            ->map(fn (array $item): array => [
                ...$item,
                'eyewear_role' => 'other',
            ])
            ->values();

        if (($data['eyewear_frame_source'] ?? null) === 'catalog'
            && filled($data['eyewear_frame_variant_id'] ?? null)) {
            $items->prepend([
                'item_kind' => 'catalog',
                'product_variant_id' => (int) $data['eyewear_frame_variant_id'],
                'quantity' => 1,
                'eyewear_role' => 'frame',
            ]);
        }

        if (($data['eyewear_frame_source'] ?? null) === 'patient'
            && (filled($data['eyewear_patient_frame_description'] ?? null)
                || filled($data['eyewear_patient_frame_price'] ?? null))) {
            $items->prepend([
                'item_kind' => 'custom_product',
                'description' => $data['eyewear_patient_frame_description'] ?? null,
                'quantity' => 1,
                'unit_price' => $data['eyewear_patient_frame_price'] ?? null,
                'eyewear_role' => 'frame',
            ]);
        }

        if (filled($data['eyewear_lens_category_id'] ?? null)) {
            $items->push([
                'item_kind' => 'lens',
                'lens_category_id' => (int) $data['eyewear_lens_category_id'],
                'quantity' => 1,
                'eyewear_role' => 'lens_package',
            ]);
        }

        foreach ($data['eyewear_lens_options'] ?? [] as $option) {
            if (blank($option['lens_option_id'] ?? null)) {
                continue;
            }

            $items->push([
                'item_kind' => 'lens_option',
                'lens_option_id' => (int) $option['lens_option_id'],
                'quantity' => 1,
                'eyewear_role' => 'lens_option',
            ]);
        }

        return [
            ...$data,
            'items' => $items->all(),
        ];
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
