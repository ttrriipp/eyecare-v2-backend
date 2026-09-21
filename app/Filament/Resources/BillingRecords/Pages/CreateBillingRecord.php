<?php

namespace App\Filament\Resources\BillingRecords\Pages;

use App\Actions\BillingRecords\CreateBillingRecord as CreateBillingRecordAction;
use App\Enums\DiscountType;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\BillingRecords\Schemas\ServiceChargeForm;
use App\Filament\Resources\OpticalOrders\Schemas\OpticalOrderCreationForm;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
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

    public function mount(
        ?string $patient = null,
        ?string $prescription = null,
    ): void {
        $patient ??= request()->query('patient');
        $prescription ??= request()->query('prescription');

        $this->patientId = filled($patient) ? (int) $patient : null;
        $this->prescriptionId = filled($prescription) ? (int) $prescription : null;

        parent::mount();

        if ($this->patientId !== null || $this->prescriptionId !== null) {
            $this->form->fill([
                'patient_id' => $this->patientId
                    ?? Prescription::query()->find($this->prescriptionId)?->patient_id,
                'prescription_id' => $this->prescriptionId,
                'include_prescription_eyewear' => $this->prescriptionId !== null,
            ]);
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
            ->whereNull('voided_at')
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
        $automaticDiscountType = fn (?string $patientId): string => $this->automaticDiscountTypeForPatient(
            $patientById($patientId),
        );
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
        $discountAmount = function (Get $get) use ($subtotal): float {
            $discountType = DiscountType::tryFrom((string) ($get('discount_type') ?? DiscountType::None->value));

            return match ($discountType) {
                DiscountType::SeniorCitizen, DiscountType::Pwd => round(
                    $subtotal($get) * (($discountType->percentage() ?? 0) / 100),
                    2,
                ),
                DiscountType::Other => max((float) ($get('discount_amount') ?? 0), 0),
                default => 0,
            };
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
                                            ->orderBy('first_name')
                                            ->get()
                                            ->mapWithKeys(fn (Patient $patient): array => [
                                                $patient->id => "{$patient->full_name} ({$patient->patient_number})",
                                            ])
                                            ->all())
                                        ->required()
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->afterStateUpdated(function (
                                            Set $set,
                                            Get $get,
                                            ?string $state,
                                            LivewireComponent $livewire,
                                        ) use ($automaticDiscountType): void {
                                            $set('prescription_id', null);
                                            $set('include_prescription_eyewear', false);
                                            $set('eyewear_frame_source', null);
                                            $set('eyewear_frame_variant_id', null);
                                            $set('eyewear_patient_frame_description', null);
                                            $set('eyewear_patient_frame_price', null);
                                            $set('eyewear_lens_category_id', null);
                                            $set('eyewear_lens_options', []);
                                            $set('discount_type', $automaticDiscountType($state));
                                            $set('discount_amount', null);
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
                                allowCatalogFrameQuantity: true,
                                allowEmpty: true,
                            ),

                            Section::make('Services')
                                ->description('Add catalog or custom services to this bill.')
                                ->schema([
                                    ServiceChargeForm::items('service_items'),
                                    Placeholder::make('service_total')
                                        ->label('Services subtotal')
                                        ->content(function (Get $get) use ($serviceSubtotal): string {
                                            return '₱'.number_format($serviceSubtotal($get), 2);
                                        }),
                                ]),
                        ]),

                    Grid::make(1)
                        ->columnSpan(['default' => 1, 'lg' => 1])
                        ->schema([
                            Section::make('Bill Preview')
                                ->description('This creates the unpaid bill when submitted.')
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
                                    Select::make('discount_type')
                                        ->label('Discount type')
                                        ->options(DiscountType::options())
                                        ->default(DiscountType::None->value)
                                        ->selectablePlaceholder(false)
                                        ->disabled(fn (Get $get): bool => auth()->user()?->isAdmin() !== true
                                            || $seniorCitizenEligible($get))
                                        ->disableOptionWhen(fn (string $value, Get $get): bool => $value === DiscountType::SeniorCitizen->value
                                            && ! $seniorCitizenEligible($get))
                                        ->dehydrated()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                                            if ($state !== DiscountType::Other->value) {
                                                $set('discount_amount', null);
                                            }
                                        }),
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
                                        ->content(function (Get $get) use ($subtotal, $discountAmount): string {
                                            return '₱'.number_format(
                                                max($subtotal($get) - $discountAmount($get), 0),
                                                2,
                                            );
                                        })
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
        $includePrescriptionEyewear = (bool) ($this->data['include_prescription_eyewear'] ?? false);

        if ($includePrescriptionEyewear
            && ($prescription === null
                || $prescription->patient_id !== $patient->id
                || ! $prescription->isCurrentVersion()
                || $prescription->isVoided())) {
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
        $combinedSubtotal = collect($orderItems)->sum(
            fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                * ((float) ($item['unit_price'] ?? 0)),
        ) + (float) $serviceItems->sum(fn (array $item): float => (float) $item['amount']);
        $discountAmount = $this->resolveDiscountAmount(
            discountType: $discountType,
            requestedAmount: filled($data['discount_amount'] ?? null)
                ? (float) $data['discount_amount']
                : null,
            subtotal: $combinedSubtotal,
        );

        return app(CreateBillingRecordAction::class)->handle(
            patient: $patient,
            creator: $creator,
            orderItems: $orderItems,
            prescription: $prescription,
            serviceItems: $serviceItems,
            discountAmount: $discountAmount,
            discountType: $discountType,
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
            ->map(fn (array $item): array => $this->normalizeCatalogItem($item))
            ->values();

        if (! $includePrescriptionEyewear) {
            return $items->all();
        }

        if (($data['eyewear_frame_source'] ?? null) === 'catalog'
            && filled($data['eyewear_frame_variant_id'] ?? null)) {
            $items->prepend($this->catalogItem(
                ProductVariant::query()
                    ->active()
                    ->with('product')
                    ->findOrFail((int) $data['eyewear_frame_variant_id']),
                quantity: 1,
            ));
        }

        if (($data['eyewear_frame_source'] ?? null) === 'patient') {
            $items->prepend([
                'item_kind' => 'custom_product',
                'description' => $data['eyewear_patient_frame_description'],
                'quantity' => 1,
                'unit_price' => $data['eyewear_patient_frame_price'],
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

    private function resolveDiscountAmount(
        string $discountType,
        ?float $requestedAmount,
        float $subtotal,
    ): ?float {
        $discount = DiscountType::tryFrom($discountType);

        return match ($discount) {
            DiscountType::SeniorCitizen, DiscountType::Pwd => round(
                $subtotal * (($discount->percentage() ?? 0) / 100),
                2,
            ),
            DiscountType::Other => max($requestedAmount ?? 0, 0),
            default => null,
        };
    }

    private function automaticDiscountTypeForPatient(?Patient $patient): string
    {
        return $this->isSeniorCitizenEligible($patient)
            ? DiscountType::SeniorCitizen->value
            : DiscountType::None->value;
    }

    private function isSeniorCitizenEligible(?Patient $patient): bool
    {
        $age = $patient?->ageInYears();
        $minimumAge = DiscountType::SeniorCitizen->minimumAge();

        return $age !== null && $minimumAge !== null && $age >= $minimumAge;
    }
}
