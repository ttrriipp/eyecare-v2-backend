<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Actions\BillingRecords\AddChargesToBilling;
use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
use App\Actions\Quotations\RecordQuotationDecision;
use App\Actions\Quotations\UpdateQuotationDraft;
use App\Enums\BillingItemSourceKind;
use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Schemas\QuotationCreationForm;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reviseItems')
                ->label('Revise Items')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Draft
                    && $this->record->jobOrder === null)
                ->schema(fn (): array => $this->revisionSchema())
                ->fillForm(fn (): array => $this->revisionFormData())
                ->modalHeading('Revise Quotation Items')
                ->slideOver()
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitActionLabel('Save Revision')
                ->action(function (array $data): void {
                    try {
                        app(UpdateQuotationDraft::class)->handle(
                            quotation: $this->record,
                            data: $data,
                            editor: auth()->user(),
                            includePrescriptionEyewear: $this->usesDedicatedEyewearLayout()
                                || array_key_exists('eyewear_lens_category_id', $data)
                                || array_key_exists('eyewear_frame_source', $data),
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot revise quotation')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record->refresh();

                    Notification::make()
                        ->title('Quotation revised')
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),

            Action::make('confirmSale')
                ->label('Confirm Sale')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Draft
                    && ! $this->record->isExpired())
                ->schema(function (): array {
                    $serviceItems = $this->record->items()
                        ->where('item_kind', CommercialItemKind::Service->value)
                        ->get();

                    $configurationItems = $this->record->productItems()
                        ->get()
                        ->map(fn ($item): string => "{$item->description} × {$item->quantity}")
                        ->values();
                    $hasProductItems = $configurationItems->isNotEmpty();

                    $prescription = $this->record->prescription;

                    return [
                        Placeholder::make('order_summary')
                            ->label('Order summary')
                            ->content(new HtmlString($configurationItems->implode('<br>')))
                            ->columnSpanFull(),

                        Placeholder::make('prescription_reference')
                            ->label('Prescription')
                            ->content($prescription?->prescription_number ?? 'No linked prescription')
                            ->visible($prescription !== null),

                        Placeholder::make('prescription_author')
                            ->label('Prescribing Optometrist')
                            ->content($prescription?->author?->full_name ?? '—')
                            ->visible($prescription !== null),

                        Placeholder::make('prescription_warning')
                            ->label('Prescription warning')
                            ->content($prescription?->isVoided() === true
                                ? 'This prescription has been voided.'
                                : 'This prescription has been superseded. Select the current version before confirming.')
                            ->visible($prescription !== null
                                && ($prescription->isVoided() || ! $prescription->isCurrentVersion()))
                            ->columnSpanFull(),

                        Select::make('fulfillment_mode')
                            ->label('Fulfillment')
                            ->options([
                                'immediate' => 'Complete sale now',
                                'prepared' => 'Prepare for pickup',
                            ])
                            ->default('prepared')
                            ->required()
                            ->live()
                            ->helperText('Use Complete sale now only for items already ready to dispense.')
                            ->visible($hasProductItems)
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state === 'immediate') {
                                    $set('uses_external_supplier', false);
                                }
                            }),

                        Toggle::make('uses_external_supplier')
                            ->label('External supplier')
                            ->default(false)
                            ->visible(fn (Get $get): bool => $hasProductItems
                                && $get('fulfillment_mode') === 'prepared'),

                        TextInput::make('recipient_name')
                            ->label('Dispensing Recipient')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $hasProductItems
                                && $get('fulfillment_mode') === 'immediate'),

                        CheckboxList::make('performed_service_item_ids')
                            ->label('Services to bill now')
                            ->helperText('Unselected services stay proposed — bill them later from "Bill Remaining Services".')
                            ->options($serviceItems->mapWithKeys(fn ($item): array => [
                                $item->id => "{$item->description} (₱".number_format((float) $item->amount, 2).')',
                            ]))
                            ->default($serviceItems->pluck('id')->take(1)->all())
                            ->visible($serviceItems->isNotEmpty()),

                        DatePicker::make('payment_due_date')
                            ->label('Payment Due Date')
                            ->native(false)
                            ->minDate(today())
                            ->nullable(),

                        TextInput::make('deposit_amount')
                            ->label('Initial Deposit')
                            ->numeric()
                            ->prefix('₱')
                            ->maxValue(fn (): float => (float) $this->record->total)
                            ->nullable(),

                        Select::make('deposit_payment_method')
                            ->label('Deposit Payment Method')
                            ->options([
                                'cash' => 'Cash',
                                'gcash' => 'GCash',
                                'bank_transfer' => 'Bank Transfer',
                                'card' => 'Credit Card',
                            ])
                            ->nullable()
                            ->visible(fn (Get $get): bool => filled($get('deposit_amount'))),

                        TextInput::make('deposit_reference')
                            ->label('Reference Number')
                            ->nullable()
                            ->visible(fn (Get $get): bool => filled($get('deposit_amount'))),
                    ];
                })
                ->requiresConfirmation()
                ->modalHeading('Confirm Sale')
                ->modalSubmitActionLabel('Confirm Sale')
                ->modalCancelActionLabel('Back')
                ->action(function (array $data): void {
                    $confirmer = auth()->user();

                    abort_unless($confirmer instanceof User, 403);

                    try {
                        $result = app(CreateOpticalOrderFromQuotation::class)->handle(
                            quotation: $this->record,
                            confirmer: $confirmer,
                            performedServiceItemIds: array_map('intval', $data['performed_service_item_ids'] ?? []),
                            paymentDueDate: filled($data['payment_due_date'] ?? null) ? Carbon::parse($data['payment_due_date']) : null,
                            depositAmount: filled($data['deposit_amount'] ?? null) ? (float) $data['deposit_amount'] : null,
                            depositPaymentMethod: $data['deposit_payment_method'] ?? null,
                            depositReference: $data['deposit_reference'] ?? null,
                            fulfillmentMode: $data['fulfillment_mode'] ?? 'prepared',
                            usesExternalSupplier: ($data['fulfillment_mode'] ?? 'prepared') === 'prepared'
                                && (bool) ($data['uses_external_supplier'] ?? false),
                            recipientName: $data['recipient_name'] ?? null,
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot confirm sale')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record->refresh();

                    Notification::make()
                        ->title('Sale confirmed')
                        ->body($result['optical_order']
                            ? "Optical Order: {$result['optical_order']->job_order_number}"
                            : 'Selected services were billed. No optical order was created.')
                        ->success()
                        ->send();

                    if ($result['optical_order'] !== null) {
                        $this->redirect(OpticalOrderResource::getUrl('edit', [
                            'record' => $result['optical_order'],
                        ]));

                        return;
                    }

                    $this->redirect(BillingRecordResource::getUrl('edit', [
                        'record' => $result['billing_record'],
                    ]));
                }),

            Action::make('billRemainingServices')
                ->label('Bill Remaining Services')
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Accepted
                    && $this->record->unbilledServiceItems()->exists())
                ->schema(fn (): array => [
                    CheckboxList::make('quotation_item_ids')
                        ->label('Services to bill now')
                        ->required()
                        ->options($this->record->unbilledServiceItems()->get()->mapWithKeys(fn ($item): array => [
                            $item->id => "{$item->description} (₱".number_format((float) $item->amount, 2).')',
                        ])),
                ])
                ->requiresConfirmation()
                ->modalHeading('Bill Remaining Services')
                ->modalDescription('Confirms the selected quoted services have now been performed and adds them to this patient\'s open bill.')
                ->action(function (array $data): void {
                    try {
                        $quotation = $this->record;

                        $billingRecord = app(ResolveOpenCheckoutBillingRecord::class)->handle(
                            patient: $quotation->patient,
                            jobOrder: $quotation->jobOrder,
                            encounter: $quotation->encounter,
                        );

                        $selectedIds = array_map('intval', $data['quotation_item_ids'] ?? []);

                        $existingQuotationItemIds = $billingRecord->items()
                            ->whereNotNull('quotation_item_id')
                            ->pluck('quotation_item_id')
                            ->toArray();

                        $newServiceIds = array_diff($selectedIds, $existingQuotationItemIds);

                        if (! empty($newServiceIds)) {
                            $serviceItems = $quotation->items()
                                ->whereIn('id', $newServiceIds)
                                ->where('item_kind', CommercialItemKind::Service->value)
                                ->get()
                                ->map(fn ($item): array => [
                                    'description' => $item->description,
                                    'quantity' => $item->quantity,
                                    'unit_price' => $item->unit_price,
                                    'amount' => $item->amount,
                                    'item_kind' => $item->item_kind->value,
                                    'quotation_item_id' => $item->id,
                                ]);

                            if ($serviceItems->isNotEmpty()) {
                                app(AddChargesToBilling::class)->handle(
                                    billingRecord: $billingRecord,
                                    sourceKind: BillingItemSourceKind::Quotation,
                                    items: $serviceItems,
                                );
                            }
                        }
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot bill services')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Services billed')
                        ->success()
                        ->send();
                }),

            Action::make('decline')
                ->label('Decline')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Draft)
                ->requiresConfirmation()
                ->modalHeading('Decline Quotation')
                ->modalDescription('This will mark the quotation as declined. This action cannot be undone.')
                ->modalSubmitActionLabel('Decline')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(1000)
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    app(RecordQuotationDecision::class)->handle($this->record, 'declined', auth()->user(), $data['reason']);
                    $this->refreshFormData(['status']);
                }),

            Action::make('viewJobOrder')
                ->label('View Optical Order')
                ->icon('heroicon-o-shopping-bag')
                ->color('gray')
                ->visible(fn (): bool => $this->record->jobOrder !== null)
                ->url(fn (): string => OpticalOrderResource::getUrl('edit', [
                    'record' => $this->record->jobOrder,
                ])),

            Action::make('viewBillingRecord')
                ->label('View Billing Record')
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->visible(fn (): bool => $this->record->billingRecord !== null)
                ->url(fn (): string => BillingRecordResource::getUrl('edit', [
                    'record' => $this->record->billingRecord,
                ])),
        ];
    }

    protected function getFormActions(): array
    {
        if ($this->record->status !== QuotationStatus::Draft) {
            return [
                Action::make('back')
                    ->label('Back')
                    ->icon('heroicon-o-arrow-left')
                    ->color('gray')
                    ->url(QuotationResource::getUrl('index')),
            ];
        }

        return parent::getFormActions();
    }

    /**
     * @return array<int, mixed>
     */
    private function revisionSchema(): array
    {
        $dedicatedPrescriptionEyewear = $this->usesDedicatedEyewearLayout();

        return [
            Section::make('Quotation being revised')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        Placeholder::make('revision_quotation_number')
                            ->label('Quotation')
                            ->content($this->record->quotation_number),
                        Placeholder::make('revision_patient')
                            ->label('Patient')
                            ->content($this->record->patient?->full_name ?? '—'),
                        Placeholder::make('revision_prescription')
                            ->label('Prescription')
                            ->content($this->record->prescription?->prescription_number ?? 'None')
                            ->visible($this->record->prescription !== null),
                        Placeholder::make('revision_status')
                            ->label('Status')
                            ->content('Draft')
                            ->badge(),
                        Placeholder::make('revision_total')
                            ->label('Current total')
                            ->content('₱'.number_format((float) $this->record->total, 2)),
                    ]),
                ])
                ->columnSpanFull(),
            ...QuotationCreationForm::components(
                patientIdResolver: fn (Get $get): ?int => $this->record->patient_id,
                prescriptionEyewearResolver: fn (Get $get): bool => $this->usesDedicatedEyewearLayout(),
                dedicatedPrescriptionEyewear: $dedicatedPrescriptionEyewear,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionFormData(): array
    {
        $dedicatedPrescriptionEyewear = $this->usesDedicatedEyewearLayout();

        $data = [
            'valid_until' => $this->record->valid_until?->toDateString(),
            'discount_amount' => (float) $this->record->discount_amount,
            'notes' => $this->record->notes,
        ];

        if ($dedicatedPrescriptionEyewear) {
            return [
                ...$data,
                ...$this->dedicatedRevisionFormData(),
            ];
        }

        return [
            ...$data,
            'items' => $this->record->items->map(fn ($item): array => $this->revisionItemData($item))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dedicatedRevisionFormData(): array
    {
        $this->record->loadMissing('items.variant.product');

        $items = $this->record->items;
        $frame = $items->first(fn ($item): bool => $item->variant?->product?->product_type === 'frame');

        if ($frame === null) {
            $frame = $items->first(fn ($item): bool => $item->item_kind === CommercialItemKind::CustomProduct
                && blank($item->product_variant_id));
        }

        $lensPackage = $items->first(fn ($item): bool => filled($item->lens_category_id));
        $lensOptions = $items
            ->filter(fn ($item): bool => filled($item->lens_option_id))
            ->map(fn ($item): array => ['lens_option_id' => $item->lens_option_id])
            ->values()
            ->all();

        $otherItems = $items
            ->reject(fn ($item): bool => ($frame !== null && $item->is($frame))
                || ($lensPackage !== null && $item->is($lensPackage))
                || filled($item->lens_option_id))
            ->map(fn ($item): array => $this->revisionItemData($item))
            ->values()
            ->all();

        return [
            'eyewear_frame_source' => $frame?->product_variant_id !== null ? 'catalog' : ($frame !== null ? 'patient' : null),
            'eyewear_frame_variant_id' => $frame?->product_variant_id,
            'eyewear_patient_frame_description' => $frame?->product_variant_id === null ? $frame?->description : null,
            'eyewear_patient_frame_price' => $frame?->product_variant_id === null ? (float) $frame?->unit_price : null,
            'eyewear_lens_category_id' => $lensPackage?->lens_category_id,
            'eyewear_lens_options' => $lensOptions,
            'items' => $otherItems,
        ];
    }

    private function usesDedicatedEyewearLayout(): bool
    {
        return $this->record->prescription !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionItemData(mixed $item): array
    {
        return [
            'item_kind' => match (true) {
                $item->item_kind === CommercialItemKind::LensOption || filled($item->lens_option_id) => 'lens_option',
                filled($item->product_variant_id) => 'catalog',
                filled($item->lens_category_id) => 'lens',
                filled($item->service_id) => 'service',
                in_array($item->item_kind?->value, CommercialItemKind::productKindValues(), true) => 'custom_product',
                default => 'custom_service',
            },
            'product_variant_id' => $item->product_variant_id,
            'lens_category_id' => $item->lens_category_id,
            'lens_option_id' => $item->lens_option_id,
            'service_id' => $item->service_id,
            'catalog_product_type' => $item->variant?->product?->product_type,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ];
    }
}
