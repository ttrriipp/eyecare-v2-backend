<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Actions\OpticalOrders\CreateDirectOpticalOrder as CreateDirectOpticalOrderAction;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\OpticalOrders\Schemas\OpticalOrderCreationForm;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Encounter;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;

class CreateDirectOpticalOrder extends CreateRecord
{
    protected static string $resource = OpticalOrderResource::class;

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

        if ($this->prescriptionId === null) {
            $this->prescriptionId = $this->resolveEncounterPrescription()?->id;
        }

        if ($this->patientId === null && $this->prescriptionId !== null) {
            $this->patientId = $this->resolvePrescription()?->patient_id;
        }

        $this->patientId ??= $this->resolveEncounter()?->patient_id;

        parent::mount();

        if ($this->patientId !== null || $this->prescriptionId !== null) {
            $this->form->fill([
                'patient_id' => $this->resolvePatient()?->id,
                'prescription_id' => $this->resolvePrescription()?->id,
                'include_prescription_eyewear' => $this->prescriptionId !== null,
                'items' => $this->prescriptionId !== null ? [] : [[
                    'item_kind' => 'catalog',
                    'quantity' => 1,
                ]],
            ]);
        }
    }

    public function getTitle(): string
    {
        return 'New Direct Optical Order';
    }

    public function form(Schema $schema): Schema
    {
        $prescriptionResolver = fn (Get $get): ?Prescription => filled($get('prescription_id'))
            ? Prescription::query()->with('author')->find((int) $get('prescription_id'))
            : null;
        $prescriptionEyewearResolver = fn (Get $get): bool => (bool) (
            $get('include_prescription_eyewear')
            ?? $get('../../include_prescription_eyewear')
            ?? false
        );
        $prescriptionOptions = fn (Get $get): array => Prescription::query()
            ->where('patient_id', $get('patient_id'))
            ->whereNull('voided_at')
            ->whereDoesntHave('nextPrescription')
            ->orderByDesc('prescribed_at')
            ->get()
            ->mapWithKeys(fn (Prescription $prescription): array => [
                $prescription->id => $prescription->prescription_number,
            ])
            ->all();
        $subtotal = fn (Get $get): float => OpticalOrderCreationForm::subtotal(
            $get,
            $prescriptionEyewearResolver,
            true,
        );
        $discountedTotal = fn (Get $get): float => max(
            $subtotal($get) - (float) ($get('discount_amount') ?? 0),
            0,
        );
        $clearPrescriptionValidation = function (LivewireComponent $livewire): void {
            $livewire->resetValidation('data.prescription_id');
        };

        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    Grid::make(1)
                        ->columnSpan(['default' => 1, 'lg' => 2])
                        ->schema([
                            Section::make('Patient & Prescription')
                                ->schema([
                                    Select::make('patient_id')
                                        ->label('Patient')
                                        ->options(fn (): array => Patient::query()
                                            ->orderBy('last_name')
                                            ->get()
                                            ->mapWithKeys(fn (Patient $patient): array => [
                                                $patient->id => "{$patient->full_name} ({$patient->patient_number})",
                                            ])
                                            ->all())
                                        ->required()
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, LivewireComponent $livewire): void {
                                            $set('prescription_id', null);
                                            $set('include_prescription_eyewear', false);
                                            $set('eyewear_frame_source', null);
                                            $set('eyewear_frame_variant_id', null);
                                            $set('eyewear_patient_frame_description', null);
                                            $set('eyewear_patient_frame_price', null);
                                            $set('eyewear_lens_category_id', null);
                                            $set('eyewear_lens_options', []);
                                            $set('items', [[
                                                'item_kind' => 'catalog',
                                                'quantity' => 1,
                                            ]]);
                                            $livewire->resetValidation('data.prescription_id');
                                        }),
                                    Select::make('prescription_id')
                                        ->label('Prescription')
                                        ->options($prescriptionOptions)
                                        ->searchable()
                                        ->preload()
                                        ->required(fn (Get $get): bool => (bool) $get('include_prescription_eyewear'))
                                        ->afterStateUpdated($clearPrescriptionValidation)
                                        ->live(),
                                    Toggle::make('include_prescription_eyewear')
                                        ->label('Include prescription eyewear')
                                        ->default($this->prescriptionId !== null)
                                        ->visible(fn (Get $get): bool => filled($get('prescription_id')))
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get, ?bool $state, LivewireComponent $livewire): void {
                                            $items = collect($get('items') ?? []);
                                            $hasEnteredItem = $items->contains(
                                                fn (array $item): bool => collect([
                                                    $item['description'] ?? null,
                                                    $item['unit_price'] ?? null,
                                                    $item['product_variant_id'] ?? null,
                                                ])->contains(fn (mixed $value): bool => filled($value)),
                                            );

                                            if ($state && ! $hasEnteredItem) {
                                                $set('items', []);
                                            } elseif (! $state && ! $hasEnteredItem) {
                                                $set('items', [[
                                                    'item_kind' => 'catalog',
                                                    'quantity' => 1,
                                                ]]);
                                            }

                                            if ($state) {
                                                $set('fulfillment_mode', 'prepared');

                                                if (blank($get('prescription_id'))) {
                                                    $livewire->addError(
                                                        'data.prescription_id',
                                                        'Select a current prescription before enabling prescription eyewear.',
                                                    );

                                                    return;
                                                }
                                            }

                                            $livewire->resetValidation('data.prescription_id');
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
                                ->columns(['default' => 1, 'md' => 2]),

                            OpticalOrderCreationForm::prescriptionEyewearSection($prescriptionEyewearResolver),
                            OpticalOrderCreationForm::itemsSection(
                                prescriptionEyewearResolver: $prescriptionEyewearResolver,
                                dedicatedPrescriptionEyewear: true,
                                includeServices: false,
                                excludeFramesFromOtherItems: true,
                            ),
                        ]),
                    Grid::make(1)
                        ->columnSpan(['default' => 1, 'lg' => 1])
                        ->schema([
                            Section::make('Fulfillment')
                                ->schema([
                                    Radio::make('fulfillment_mode')
                                        ->label('Fulfillment')
                                        ->options([
                                            'immediate' => 'Complete sale now',
                                            'prepared' => 'Prepare for pickup',
                                        ])
                                        ->disableOptionWhen(fn (string $value, Get $get): bool => $value === 'immediate'
                                            && $prescriptionEyewearResolver($get))
                                        ->default('prepared')
                                        ->required()
                                        ->live()
                                        ->in(fn (Radio $component): array => array_keys($component->getEnabledOptions()))
                                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                                            if ($state === 'immediate') {
                                                $set('uses_external_supplier', false);
                                            }
                                        }),
                                    Toggle::make('uses_external_supplier')
                                        ->label('External lab/supplier')
                                        ->default(false)
                                        ->visible(fn (Get $get): bool => $get('fulfillment_mode') === 'prepared'),
                                    TextInput::make('recipient_name')
                                        ->label('Dispensing Recipient')
                                        ->maxLength(255)
                                        ->visible(fn (Get $get): bool => $get('fulfillment_mode') === 'immediate'),
                                ]),
                            Section::make('Payment')
                                ->schema([
                                    Placeholder::make('order_total')
                                        ->label('Subtotal')
                                        ->content(fn (Get $get): string => '₱'.number_format($subtotal($get), 2)),
                                    TextInput::make('discount_amount')
                                        ->label('Discount')
                                        ->prefix('₱')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(fn (Get $get): float => $subtotal($get))
                                        ->default(0)
                                        ->disabled(fn (): bool => auth()->user()?->isAdmin() !== true)
                                        ->dehydrated()
                                        ->live(onBlur: true),
                                    Placeholder::make('discounted_total')
                                        ->label('Total')
                                        ->content(fn (Get $get): string => '₱'.number_format($discountedTotal($get), 2)),
                                    TextInput::make('deposit_amount')
                                        ->label('Initial payment')
                                        ->numeric()
                                        ->prefix('₱')
                                        ->minValue(0)
                                        ->maxValue(fn (Get $get): float => $discountedTotal($get))
                                        ->live(onBlur: true)
                                        ->nullable(),
                                    Select::make('deposit_payment_method')
                                        ->label('Payment method')
                                        ->options([
                                            'cash' => 'Cash',
                                            'gcash' => 'GCash',
                                            'bank_transfer' => 'Bank Transfer',
                                            'card' => 'Card',
                                        ])
                                        ->default('cash')
                                        ->required(fn (Get $get): bool => filled($get('deposit_amount')))
                                        ->visible(fn (Get $get): bool => filled($get('deposit_amount'))),
                                    Placeholder::make('balance_due')
                                        ->label('Balance due')
                                        ->content(fn (Get $get): string => '₱'.number_format(
                                            max($discountedTotal($get) - (float) ($get('deposit_amount') ?? 0), 0),
                                            2,
                                        )),
                                    DatePicker::make('payment_due_date')
                                        ->label('Payment due date')
                                        ->native(false)
                                        ->minDate(today())
                                        ->nullable(),
                                    TextInput::make('deposit_reference')
                                        ->label('Reference number')
                                        ->nullable()
                                        ->visible(fn (Get $get): bool => filled($get('deposit_amount'))),
                                ]),
                        ]),
                ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $creator = auth()->user();

        abort_unless($creator instanceof User, 403);

        $patient = Patient::query()->find($data['patient_id'] ?? null);
        $prescription = filled($data['prescription_id'] ?? null)
            ? Prescription::query()->find($data['prescription_id'])
            : null;
        $includePrescriptionEyewear = (bool) ($this->data['include_prescription_eyewear'] ?? false);

        if ($patient === null) {
            Notification::make()
                ->title('Cannot create order')
                ->body('A patient is required.')
                ->danger()
                ->send();

            $this->halt();
        }

        if ($includePrescriptionEyewear
            && ($prescription === null
                || $prescription->patient_id !== $patient->id
                || ! $prescription->isCurrentVersion()
                || $prescription->isVoided())) {
            $this->addError('data.prescription_id', 'Select a current prescription before creating prescription eyewear.');
            $this->halt();
        }

        if ($includePrescriptionEyewear && blank($data['eyewear_lens_category_id'] ?? null)) {
            $this->addError('data.eyewear_lens_category_id', 'Select a lens package before creating prescription eyewear.');
            $this->halt();
        }

        $data['items'] = $this->normalizeItems($data, $includePrescriptionEyewear);

        try {
            $result = app(CreateDirectOpticalOrderAction::class)->handle(
                patient: $patient,
                creator: $creator,
                items: $data['items'],
                fulfillmentMode: $data['fulfillment_mode'] ?? 'prepared',
                usesExternalSupplier: ($data['fulfillment_mode'] ?? 'prepared') === 'prepared'
                    && (bool) ($data['uses_external_supplier'] ?? false),
                prescription: $prescription,
                paymentDueDate: filled($data['payment_due_date'] ?? null)
                    ? Carbon::parse($data['payment_due_date'])
                    : null,
                depositAmount: filled($data['deposit_amount'] ?? null)
                    ? (float) $data['deposit_amount']
                    : null,
                depositPaymentMethod: $data['deposit_payment_method'] ?? null,
                depositReference: $data['deposit_reference'] ?? null,
                recipientName: $data['recipient_name'] ?? null,
                discountAmount: filled($data['discount_amount'] ?? null)
                    ? (float) $data['discount_amount']
                    : null,
            );

            return $result['job_order'];
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Cannot create order')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return OpticalOrderResource::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Order created';
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('createOrder')
                ->label('Create Order & Billing')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->action(function (): void {
                    $this->create();
                }),
            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->url(OpticalOrderResource::getUrl('index'))
                ->cancelParentActions(),
        ];
    }

    protected function hasCreateAnother(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $data, bool $includePrescriptionEyewear): array
    {
        $items = collect($data['items'] ?? [])
            ->filter(fn (array $item): bool => collect([
                $item['description'] ?? null,
                $item['unit_price'] ?? null,
                $item['product_variant_id'] ?? null,
                $item['lens_category_id'] ?? null,
                $item['lens_option_id'] ?? null,
            ])->contains(fn (mixed $value): bool => filled($value)))
            ->map(fn (array $item): array => $this->normalizeCatalogItem($item))
            ->values();

        if (! $includePrescriptionEyewear) {
            return $items->all();
        }

        if (($data['eyewear_frame_source'] ?? null) === 'catalog'
            && filled($data['eyewear_frame_variant_id'] ?? null)) {
            $items->prepend($this->catalogItem(
                ProductVariant::query()->active()->with('product')->findOrFail((int) $data['eyewear_frame_variant_id']),
                quantity: 1,
            ));
        }

        if (($data['eyewear_frame_source'] ?? null) === 'patient') {
            $items->prepend([
                'item_kind' => 'custom',
                'description' => $data['eyewear_patient_frame_description'],
                'quantity' => 1,
                'unit_price' => $data['eyewear_patient_frame_price'],
            ]);
        }

        if (filled($data['eyewear_lens_category_id'] ?? null)) {
            $lensCategory = LensCategory::query()->active()->findOrFail((int) $data['eyewear_lens_category_id']);
            $items->push([
                'item_kind' => 'lens',
                'description' => $lensCategory->name,
                'quantity' => 1,
                'unit_price' => $lensCategory->price,
                'lens_category_id' => $lensCategory->id,
            ]);
        }

        foreach ($data['eyewear_lens_options'] ?? [] as $option) {
            if (blank($option['lens_option_id'] ?? null)) {
                continue;
            }

            $lensOption = LensOption::query()->active()->findOrFail((int) $option['lens_option_id']);
            $items->push([
                'item_kind' => 'lens_option',
                'description' => $lensOption->name,
                'quantity' => 1,
                'unit_price' => $lensOption->price,
                'lens_option_id' => $lensOption->id,
            ]);
        }

        return $items->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeCatalogItem(array $item): array
    {
        if (filled($item['product_variant_id'] ?? null)) {
            return $this->catalogItem(
                ProductVariant::query()->active()->with('product')->findOrFail((int) $item['product_variant_id']),
                quantity: (int) ($item['quantity'] ?? 1),
            );
        }

        if (filled($item['lens_category_id'] ?? null)) {
            $lensCategory = LensCategory::query()->active()->findOrFail((int) $item['lens_category_id']);

            return [
                ...$item,
                'item_kind' => 'lens',
                'description' => $lensCategory->name,
                'unit_price' => $lensCategory->price,
                'quantity' => 1,
            ];
        }

        if (filled($item['lens_option_id'] ?? null)) {
            $lensOption = LensOption::query()->active()->findOrFail((int) $item['lens_option_id']);

            return [
                ...$item,
                'item_kind' => 'lens_option',
                'description' => $lensOption->name,
                'unit_price' => $lensOption->price,
                'quantity' => 1,
            ];
        }

        return [
            ...$item,
            'item_kind' => 'custom',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogItem(ProductVariant $variant, int $quantity): array
    {
        return [
            'item_kind' => 'catalog',
            'description' => "{$variant->product->name} — {$variant->name}",
            'quantity' => $variant->product->product_type === 'frame' ? 1 : $quantity,
            'unit_price' => $variant->price,
            'product_variant_id' => $variant->id,
        ];
    }

    private function resolvePatient(): ?Patient
    {
        return $this->patientId !== null ? Patient::query()->find($this->patientId) : null;
    }

    private function resolvePrescription(): ?Prescription
    {
        return $this->prescriptionId !== null ? Prescription::query()->find($this->prescriptionId) : null;
    }

    private function resolveEncounter(): ?Encounter
    {
        return $this->encounterId !== null ? Encounter::query()->find($this->encounterId) : null;
    }

    private function resolveEncounterPrescription(): ?Prescription
    {
        return $this->resolveEncounter()?->prescriptions()
            ->whereNull('voided_at')
            ->whereDoesntHave('nextPrescription')
            ->latest('id')
            ->first();
    }
}
