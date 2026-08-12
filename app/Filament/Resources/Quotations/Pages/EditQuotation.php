<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Actions\BillingRecords\BillRemainingQuotedServices;
use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
use App\Actions\Quotations\PresentQuotation;
use App\Actions\Quotations\RecordQuotationDecision;
use App\Actions\Quotations\UpdateQuotationDraft;
use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Schemas\QuotationCreationForm;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('present')
                ->label('Present')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Draft)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(PresentQuotation::class)->handle($this->record, auth()->user());
                    $this->refreshFormData(['status']);
                }),

            Action::make('reviseItems')
                ->label('Revise Items')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Draft
                    && $this->record->jobOrder === null)
                ->schema(QuotationCreationForm::components(
                    patientIdResolver: fn (Get $get): ?int => $this->record->patient_id,
                ))
                ->fillForm(fn (): array => [
                    'valid_until' => $this->record->valid_until?->toDateString(),
                    'discount_amount' => (float) $this->record->discount_amount,
                    'notes' => $this->record->notes,
                    'items' => $this->record->items->map(fn ($item): array => [
                        'item_type' => match (true) {
                            $item->item_kind === CommercialItemKind::LensOption || filled($item->lens_option_id) => 'lens_option',
                            filled($item->product_variant_id) => 'catalog',
                            filled($item->lens_category_id) => 'lens',
                            filled($item->service_id) => 'service',
                            $item->item_type === TransactionItemType::Product => 'custom_product',
                            default => 'custom_service',
                        },
                        'product_variant_id' => $item->product_variant_id,
                        'lens_category_id' => $item->lens_category_id,
                        'lens_option_id' => $item->lens_option_id,
                        'service_id' => $item->service_id,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                    ])->all(),
                ])
                ->modalHeading('Revise Quotation Items')
                ->modalSubmitActionLabel('Save Revision')
                ->action(function (array $data): void {
                    // A revision to a Presented quotation must be shown to the
                    // patient again before it can move forward — the action
                    // itself reverts status to Draft when this applies.
                    try {
                        app(UpdateQuotationDraft::class)->handle($this->record, $data, auth()->user());
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
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Draft)
                ->schema(function (): array {
                    $serviceItems = $this->record->items()
                        ->where('item_type', TransactionItemType::Service)
                        ->get();

                    $configurationItems = $this->record->productItems()
                        ->get()
                        ->filter(fn ($item): bool => $item->item_kind === CommercialItemKind::LensPackage
                            || $item->item_kind === CommercialItemKind::LensOption
                            || filled($item->lens_category_id)
                            || filled($item->lens_option_id))
                        ->map(fn ($item): string => "{$item->description} × {$item->quantity}")
                        ->values();

                    $prescription = $this->record->prescription;

                    return [
                        Placeholder::make('corrective_eyewear_summary')
                            ->label('Corrective Eyewear Configuration')
                            ->content($configurationItems->isNotEmpty()
                                ? $configurationItems->implode('; ')
                                : 'No corrective eyewear configuration selected.')
                            ->columnSpanFull(),

                        Placeholder::make('prescription_reference')
                            ->label('Prescription')
                            ->content($prescription?->prescription_number ?? 'No linked prescription')
                            ->visible($prescription !== null),

                        Placeholder::make('prescription_author')
                            ->label('Prescribing Optometrist')
                            ->content($prescription?->author?->full_name ?? '—')
                            ->visible($prescription !== null),

                        CheckboxList::make('performed_service_item_ids')
                            ->label('Services to bill now')
                            ->helperText('Unselected services stay proposed — bill them later from "Bill Remaining Services".')
                            ->options($serviceItems->mapWithKeys(fn ($item): array => [
                                $item->id => "{$item->description} (₱".number_format((float) $item->amount, 2).')',
                            ]))
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
                ->modalDescription('Review the corrective-eyewear configuration and charges before confirming. This creates the Optical Order from product lines and bills any selected services.')
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

                    $this->refreshFormData(['status']);
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
                        app(BillRemainingQuotedServices::class)->handle(
                            quotation: $this->record,
                            quotationItemIds: array_map('intval', $data['quotation_item_ids'] ?? []),
                        );
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
                ->label('Void / Decline')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Draft)
                ->requiresConfirmation()
                ->modalHeading('Void / Decline Quotation')
                ->modalDescription('This will mark the quotation as declined. This action cannot be undone.')
                ->modalSubmitActionLabel('Void / Decline')
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
        ];
    }
}
