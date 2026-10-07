<?php

namespace App\Filament\Resources\BillingRecords\Pages;

use App\Actions\BillingRecords\CalculatePhilippineVatSummary;
use App\Actions\BillingRecords\CreateBillingRecord as CreateBillingRecordAction;
use App\Enums\DiscountType;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\BillingRecords\Schemas\ServiceChargeForm;
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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;

class CreateBillingRecord extends CreateRecord
{
    protected static string $resource = BillingRecordResource::class;

    protected static bool $canCreateAnother = false;

    public ?int $patientId = null;

    public ?int $prescriptionId = null;

    public ?int $encounterId = null;

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

        if ($this->patientId === null) {
            $this->patientId = $this->resolvePrescription()?->patient_id;
        }

        $this->patientId ??= $this->resolveEncounter()?->patient_id;

        parent::mount();

        if ($this->encounterId !== null || $this->patientId !== null || $this->prescriptionId !== null) {
            $prefill = [
                'patient_id' => $this->patientId,
                'prescription_id' => $this->prescriptionId,
                'include_prescription_eyewear' => $this->prescriptionId !== null,
            ];

            if ($this->encounterId !== null) {
                $prefill['service_items'] = [[
                    'service_source' => 'catalog',
                    'quantity' => 1,
                ]];
            }

            $this->form->fill($prefill);
        }
    }

    public function getTitle(): string
    {
        return 'Create Bill';
    }

    public function form(Schema $schema): Schema
    {
        $prescriptionResolver = fn (Get $get): ?Prescription => filled($get('prescription_id'))
            ? Prescription::query()
                ->with('author')
                ->find((int) $get('prescription_id'))
            : null;
        $prescriptionEyewearResolver = fn (Get $get): bool => (bool) (
            $get('include_prescription_eyewear')
            ?? $get('../../include_prescription_eyewear')
            ?? false
        );
        $prescriptionOptions = fn (Get $get): array => Prescription::query()
            ->where('patient_id', $get('patient_id'))
            ->whereNull('cancelled_at')
            ->whereDoesntHave('nextPrescription')
            ->orderByDesc('prescribed_at')
            ->get()
            ->mapWithKeys(fn (Prescription $prescription): array => [
                $prescription->id => $prescription->prescription_number,
            ])
            ->all();
        $patientById = fn (mixed $patientId): ?Patient => filled($patientId)
            ? Patient::query()->find((int) $patientId)
            : null;
        $seniorCitizenEligible = fn (Get $get): bool => $this->isSeniorCitizenEligible(
            $patientById($get('patient_id')),
        );
        $productSubtotal = fn (Get $get): float => OpticalOrderCreationForm::subtotal(
            $get,
            $prescriptionEyewearResolver,
            true,
        );
        $serviceSubtotal = fn (Get $get): float => collect($get('service_items') ?? [])->sum(
            fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                * ((float) ($item['unit_price'] ?? 0)),
        );
        $subtotal = fn (Get $get): float => $productSubtotal($get) + $serviceSubtotal($get);
        $vatSummary = function (Get $get) use ($prescriptionEyewearResolver): array {
            $discountType = DiscountType::tryFrom((string) ($get('discount_type') ?? DiscountType::None->value));
            $lines = OpticalOrderCreationForm::vatLines(
                $get,
                $prescriptionEyewearResolver,
                true,
            );
            $serviceLines = collect($get('service_items') ?? [])
                ->map(fn (array $item): array => [
                    'amount' => ((float) ($item['quantity'] ?? 0)) * ((float) ($item['unit_price'] ?? 0)),
                    'vat_treatment' => $item['vat_treatment'] ?? 'vatable',
                    'statutory_discount_eligible' => (bool) ($item['statutory_discount_eligible'] ?? false),
                ])
                ->filter(fn (array $item): bool => $item['amount'] > 0)
                ->all();

            return app(CalculatePhilippineVatSummary::class)->handle(
                items: [...$lines, ...$serviceLines],
                discountType: $discountType ?? DiscountType::None,
                discountAmount: $discountType === DiscountType::Other
                    ? max((float) ($get('discount_amount') ?? 0), 0)
                    : 0,
            );
        };
        $discountAmount = fn (Get $get): float => $vatSummary($get)['discount_cents'] / 100;

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
                                            ->orderBy('first_name')
                                            ->get()
                                            ->mapWithKeys(fn (Patient $patient): array => [
                                                $patient->id => "{$patient->full_name} ({$patient->patient_number})",
                                            ])
                                            ->all())
                                        ->required()
                                        ->searchable()
                                        ->preload()
                                        ->disabled(fn (): bool => $this->encounterId !== null)
                                        ->dehydrated()
                                        ->live()
                                        ->afterStateUpdated(function (
                                            Set $set,
                                            Get $get,
                                            ?string $state,
                                            LivewireComponent $livewire,
                                        ): void {
                                            $set('prescription_id', null);
                                            $set('include_prescription_eyewear', false);
                                            $set('eyewear_frame_source', null);
                                            $set('eyewear_frame_variant_id', null);
                                            $set('eyewear_patient_frame_description', null);
                                            $set('eyewear_patient_frame_price', null);
                                            $set('eyewear_lens_category_id', null);
                                            $set('eyewear_lens_options', []);
                                            $set('discount_type', DiscountType::None->value);
                                            $set('discount_amount', null);
                                            $set('discount_eligibility_verified', false);
                                            $livewire->resetValidation('data.prescription_id');

                                            if (blank($get('items'))) {
                                                $set('items', []);
                                            }
                                        }),
                                    Placeholder::make('patient_age')
                                        ->label('Patient age')
                                        ->content(function (Get $get) use ($patientById): string {
                                            $patient = $patientById($get('patient_id'));

                                            if ($patient === null) {
                                                return '—';
                                            }

                                            $age = $patient->ageInYears();

                                            return $age === null ? 'Not recorded' : "{$age} years old";
                                        })
                                        ->visible(fn (Get $get): bool => filled($get('patient_id'))),
                                    Select::make('prescription_id')
                                        ->label('Prescription')
                                        ->options($prescriptionOptions)
                                        ->searchable()
                                        ->preload()
                                        ->required(fn (Get $get): bool => (bool) $get('include_prescription_eyewear'))
                                        ->live(),
                                    Toggle::make('include_prescription_eyewear')
                                        ->label('Include prescription eyewear')
                                        ->visible(fn (Get $get): bool => filled($get('prescription_id')))
                                        ->live()
                                        ->afterStateUpdated(function (
                                            Set $set,
                                            Get $get,
                                            ?bool $state,
                                            LivewireComponent $livewire,
                                        ): void {
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
                                            }

                                            if ($state && blank($get('prescription_id'))) {
                                                $livewire->addError(
                                                    'data.prescription_id',
                                                    'Select a current prescription before enabling prescription eyewear.',
                                                );

                                                return;
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
                                            $prescriptionResolver($get)->isCancelled() => 'Cancelled',
                                            $prescriptionResolver($get)->isCurrentVersion() => 'Current',
                                            default => 'Superseded',
                                        })
                                        ->badge()
                                        ->color(fn (Get $get): string => match (true) {
                                            $prescriptionResolver($get)?->isCancelled() === true => 'danger',
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
                                allowCatalogFrameQuantity: true,
                                allowEmpty: true,
                            ),

                            Section::make('Services')
                                ->schema([
                                    ServiceChargeForm::items('service_items'),
                                ]),
                        ]),

                    Grid::make(1)
                        ->columnSpan(['default' => 1, 'lg' => 1])
                        ->schema([
                            Section::make('Bill Preview')
                                ->schema([
                                    Placeholder::make('product_subtotal')
                                        ->label('Optical Order')
                                        ->content(function (Get $get) use ($productSubtotal): string {
                                            return '₱'.number_format($productSubtotal($get), 2);
                                        }),
                                    Placeholder::make('service_subtotal')
                                        ->label('Services')
                                        ->content(function (Get $get) use ($serviceSubtotal): string {
                                            return '₱'.number_format($serviceSubtotal($get), 2);
                                        }),
                                    Placeholder::make('bill_subtotal')
                                        ->label('Subtotal')
                                        ->content(function (Get $get) use ($subtotal): string {
                                            return '₱'.number_format($subtotal($get), 2);
                                        }),
                                    Placeholder::make('vatable_sales')
                                        ->label('VATable sales (VAT-exclusive)')
                                        ->content(fn (Get $get): string => '₱'.number_format(
                                            $vatSummary($get)['vatable_sales_cents'] / 100,
                                            2,
                                        )),
                                    Placeholder::make('vat_amount')
                                        ->label('VAT (12%, included)')
                                        ->content(fn (Get $get): string => '₱'.number_format(
                                            $vatSummary($get)['vat_amount_cents'] / 100,
                                            2,
                                        )),
                                    Select::make('discount_type')
                                        ->label('Discount type')
                                        ->options(array_replace(
                                            DiscountType::options(),
                                            [DiscountType::Other->value => 'Custom'],
                                        ))
                                        ->default(DiscountType::None->value)
                                        ->selectablePlaceholder(false)
                                        ->disabled(fn (): bool => auth()->user()?->hasPanelRole() !== true)
                                        ->disableOptionWhen(fn (string $value, Get $get): bool => $value === DiscountType::SeniorCitizen->value
                                            && ! $seniorCitizenEligible($get))
                                        ->dehydrated()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                                            if ($state !== DiscountType::Other->value) {
                                                $set('discount_amount', null);
                                            }

                                            if (! in_array($state, [DiscountType::SeniorCitizen->value, DiscountType::Pwd->value], true)) {
                                                $set('discount_eligibility_verified', false);
                                            }
                                        }),
                                    Toggle::make('discount_eligibility_verified')
                                        ->label('Eligibility verified')
                                        ->helperText('Check valid ID/proof and exclusive use first.')
                                        ->default(false)
                                        ->visible(fn (Get $get): bool => in_array(
                                            $get('discount_type'),
                                            [DiscountType::SeniorCitizen->value, DiscountType::Pwd->value],
                                            true,
                                        ))
                                        ->required(fn (Get $get): bool => in_array(
                                            $get('discount_type'),
                                            [DiscountType::SeniorCitizen->value, DiscountType::Pwd->value],
                                            true,
                                        ))
                                        ->dehydrated(),
                                    Placeholder::make('statutory_discount_amount')
                                        ->label('Discount amount')
                                        ->content(fn (Get $get): string => '₱'.number_format(
                                            $discountAmount($get),
                                            2,
                                        ))
                                        ->visible(fn (Get $get): bool => in_array(
                                            $get('discount_type'),
                                            [DiscountType::SeniorCitizen->value, DiscountType::Pwd->value],
                                            true,
                                        )),
                                    Placeholder::make('vat_exemption_amount')
                                        ->label('VAT exemption')
                                        ->content(fn (Get $get): string => '₱'.number_format(
                                            $vatSummary($get)['vat_exemption_cents'] / 100,
                                            2,
                                        ))
                                        ->visible(fn (Get $get): bool => in_array(
                                            $get('discount_type'),
                                            [DiscountType::SeniorCitizen->value, DiscountType::Pwd->value],
                                            true,
                                        )),
                                    TextInput::make('discount_amount')
                                        ->label('Custom discount')
                                        ->prefix('₱')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step(0.01)
                                        ->extraInputAttributes(['class' => 'price-input'])
                                        ->maxValue(fn (Get $get): float => $subtotal($get))
                                        ->default(0)
                                        ->disabled(fn (): bool => auth()->user()?->isAdmin() !== true)
                                        ->visible(fn (Get $get): bool => $get('discount_type') === DiscountType::Other->value)
                                        ->required(fn (Get $get): bool => $get('discount_type') === DiscountType::Other->value)
                                        ->dehydrated()
                                        ->live(onBlur: true),
                                    Placeholder::make('bill_total')
                                        ->label('Total')
                                        ->content(fn (Get $get): string => '₱'.number_format(
                                            $vatSummary($get)['total_cents'] / 100,
                                            2,
                                        ))
                                        ->extraAttributes(['class' => 'text-lg font-semibold']),
                                ])
                                ->columns(2),

                            Section::make('Payment Details')
                                ->schema([
                                    DatePicker::make('payment_due_date')
                                        ->label('Payment due date')
                                        ->native(false)
                                        ->minDate(today())
                                        ->nullable(),
                                    Textarea::make('notes')
                                        ->label('Notes')
                                        ->maxLength(2000)
                                        ->rows(4)
                                        ->columnSpanFull(),
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

        if ($patient === null) {
            throw ValidationException::withMessages([
                'patient_id' => ['A patient is required.'],
            ]);
        }

        $prescription = filled($data['prescription_id'] ?? null)
            ? Prescription::query()->find((int) $data['prescription_id'])
            : null;
        $encounter = $this->resolveEncounter();
        $includePrescriptionEyewear = (bool) ($this->data['include_prescription_eyewear'] ?? false);

        if ($this->encounterId !== null
            && ($encounter === null || $encounter->patient_id !== $patient->id)) {
            throw ValidationException::withMessages([
                'patient_id' => ['The selected encounter does not belong to this patient.'],
            ]);
        }

        if ($includePrescriptionEyewear
            && ($prescription === null
                || $prescription->patient_id !== $patient->id
                || ! $prescription->isCurrentVersion()
                || $prescription->isCancelled())) {
            throw ValidationException::withMessages([
                'prescription_id' => ['Select a current prescription before creating prescription eyewear.'],
            ]);
        }

        $orderItems = $this->normalizeItems($data, $includePrescriptionEyewear);
        $serviceItems = $this->normalizeOptionalServiceItems($data['service_items'] ?? []);

        if ($orderItems === [] && $serviceItems->isEmpty()) {
            throw ValidationException::withMessages([
                'bill' => ['Add at least one product or service line before creating the bill.'],
            ]);
        }

        $discountType = $data['discount_type'] ?? DiscountType::None->value;
        $discount = DiscountType::tryFrom((string) $discountType);

        if ($discount === null) {
            throw ValidationException::withMessages([
                'discount_type' => ['Select a valid discount type.'],
            ]);
        }

        if ($discount->isStatutory()) {
            if (! (bool) ($data['discount_eligibility_verified'] ?? false)) {
                throw ValidationException::withMessages([
                    'discount_eligibility_verified' => ['Verify the patient’s entitlement and exclusive use before applying this discount.'],
                ]);
            }

            $hasEligibleLine = collect($orderItems)
                ->merge($serviceItems)
                ->contains(fn (array $item): bool => (bool) ($item['statutory_discount_eligible'] ?? false));

            if (! $hasEligibleLine) {
                throw ValidationException::withMessages([
                    'discount_type' => ['Choose an SC/PWD-eligible product or service before applying this discount.'],
                ]);
            }
        }

        $discountAmount = $discount->isStatutory()
            ? null
            : $this->resolveDiscountAmount(
                discountType: $discountType,
                requestedAmount: filled($data['discount_amount'] ?? null)
                    ? (float) $data['discount_amount']
                    : null,
            );

        return app(CreateBillingRecordAction::class)->handle(
            patient: $patient,
            creator: $creator,
            orderItems: $orderItems,
            prescription: $prescription,
            encounter: $encounter,
            serviceItems: $serviceItems,
            discountAmount: $discountAmount,
            discountType: $discountType,
            discountEligibilityVerified: (bool) ($data['discount_eligibility_verified'] ?? false),
            paymentDueDate: filled($data['payment_due_date'] ?? null)
                ? Carbon::parse($data['payment_due_date'])
                : null,
            notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
        );
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Create Bill');
    }

    protected function getRedirectUrl(): string
    {
        return BillingRecordResource::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Bill created';
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
            ->map(function (array $item): array {
                $normalizedItem = $this->normalizeCatalogItem($item);

                return [
                    ...$normalizedItem,
                    'vat_treatment' => $item['vat_treatment']
                        ?? $normalizedItem['vat_treatment']
                        ?? 'vatable',
                    'statutory_discount_eligible' => (bool) ($item['statutory_discount_eligible']
                        ?? $normalizedItem['statutory_discount_eligible']
                        ?? false),
                ];
            })
            ->values();

        if (! $includePrescriptionEyewear) {
            return $items->all();
        }

        if (($data['eyewear_frame_source'] ?? null) === 'catalog'
            && filled($data['eyewear_frame_variant_id'] ?? null)) {
            $catalogFrame = $this->catalogItem(
                ProductVariant::query()
                    ->active()
                    ->with('product')
                    ->findOrFail((int) $data['eyewear_frame_variant_id']),
                quantity: 1,
            );
            $items->prepend([
                ...$catalogFrame,
                'vat_treatment' => auth()->user()?->isAdmin() === true
                    ? ($data['eyewear_vat_treatment'] ?? $catalogFrame['vat_treatment'])
                    : $catalogFrame['vat_treatment'],
                'statutory_discount_eligible' => auth()->user()?->isAdmin() === true
                    ? (bool) ($data['eyewear_statutory_discount_eligible'] ?? $catalogFrame['statutory_discount_eligible'])
                    : $catalogFrame['statutory_discount_eligible'],
            ]);
        }

        if (($data['eyewear_frame_source'] ?? null) === 'patient') {
            $items->prepend([
                'item_kind' => 'custom_product',
                'description' => $data['eyewear_patient_frame_description'],
                'quantity' => 1,
                'unit_price' => $data['eyewear_patient_frame_price'],
                'vat_treatment' => $data['eyewear_vat_treatment'] ?? 'vatable',
                'statutory_discount_eligible' => (bool) ($data['eyewear_statutory_discount_eligible'] ?? false),
            ]);
        }

        if (filled($data['eyewear_lens_category_id'] ?? null)) {
            $lensCategory = LensCategory::query()
                ->active()
                ->findOrFail((int) $data['eyewear_lens_category_id']);
            $items->push([
                'item_kind' => 'lens',
                'description' => $lensCategory->name,
                'quantity' => 1,
                'unit_price' => $lensCategory->price,
                'lens_category_id' => $lensCategory->id,
                'vat_treatment' => $data['eyewear_vat_treatment'] ?? 'vatable',
                'statutory_discount_eligible' => (bool) ($data['eyewear_statutory_discount_eligible'] ?? false),
            ]);
        }

        foreach ($data['eyewear_lens_options'] ?? [] as $option) {
            if (blank($option['lens_option_id'] ?? null)) {
                continue;
            }

            $lensOption = LensOption::query()
                ->active()
                ->findOrFail((int) $option['lens_option_id']);
            $items->push([
                'item_kind' => 'lens_option',
                'description' => $lensOption->name,
                'quantity' => 1,
                'unit_price' => $lensOption->price,
                'lens_option_id' => $lensOption->id,
                'vat_treatment' => $data['eyewear_vat_treatment'] ?? 'vatable',
                'statutory_discount_eligible' => (bool) ($data['eyewear_statutory_discount_eligible'] ?? false),
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
                ProductVariant::query()
                    ->active()
                    ->with('product')
                    ->findOrFail((int) $item['product_variant_id']),
                quantity: (int) ($item['quantity'] ?? 1),
            );
        }

        if (filled($item['lens_category_id'] ?? null)) {
            $lensCategory = LensCategory::query()
                ->active()
                ->findOrFail((int) $item['lens_category_id']);

            return [
                ...$item,
                'item_kind' => 'lens',
                'description' => $lensCategory->name,
                'unit_price' => $lensCategory->price,
                'quantity' => 1,
                'vat_treatment' => $item['vat_treatment'] ?? 'vatable',
                'statutory_discount_eligible' => (bool) ($item['statutory_discount_eligible'] ?? false),
            ];
        }

        if (filled($item['lens_option_id'] ?? null)) {
            $lensOption = LensOption::query()
                ->active()
                ->findOrFail((int) $item['lens_option_id']);

            return [
                ...$item,
                'item_kind' => 'lens_option',
                'description' => $lensOption->name,
                'unit_price' => $lensOption->price,
                'quantity' => 1,
                'vat_treatment' => $item['vat_treatment'] ?? 'vatable',
                'statutory_discount_eligible' => (bool) ($item['statutory_discount_eligible'] ?? false),
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
            'quantity' => $quantity,
            'unit_price' => $variant->price,
            'product_variant_id' => $variant->id,
            'vat_treatment' => $variant->product->vat_treatment?->value ?? 'vatable',
            'statutory_discount_eligible' => (bool) $variant->product->statutory_discount_eligible,
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function normalizeOptionalServiceItems(array $items): Collection
    {
        $hasLine = collect($items)->contains(function (mixed $item): bool {
            if (! is_array($item)) {
                return false;
            }

            return collect([
                $item['service_id'] ?? null,
                $item['description'] ?? null,
                $item['unit_price'] ?? null,
            ])->contains(fn (mixed $value): bool => filled($value));
        });

        return $hasLine ? ServiceChargeForm::normalizeItems($items) : collect();
    }

    private function resolveDiscountAmount(string $discountType, ?float $requestedAmount): ?float
    {
        $discount = DiscountType::tryFrom($discountType);

        return match ($discount) {
            DiscountType::Other => max($requestedAmount ?? 0, 0),
            default => null,
        };
    }

    private function isSeniorCitizenEligible(?Patient $patient): bool
    {
        $age = $patient?->ageInYears();
        $minimumAge = DiscountType::SeniorCitizen->minimumAge();

        return $age !== null && $minimumAge !== null && $age >= $minimumAge;
    }

    private function resolveEncounter(): ?Encounter
    {
        return $this->encounterId !== null
            ? Encounter::query()->find($this->encounterId)
            : null;
    }

    private function resolveEncounterPrescription(): ?Prescription
    {
        return $this->resolveEncounter()?->prescriptions()
            ->whereNull('cancelled_at')
            ->whereDoesntHave('nextPrescription')
            ->latest('id')
            ->first();
    }

    private function resolvePrescription(): ?Prescription
    {
        return $this->prescriptionId !== null
            ? Prescription::query()->find($this->prescriptionId)
            : null;
    }
}
