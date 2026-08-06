<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Actions\BillingRecords\BillRemainingQuotedServices;
use App\Actions\Quotations\ConfirmQuotationSale;
use App\Actions\Quotations\PresentQuotation;
use App\Actions\Quotations\RecordQuotationDecision;
use App\Actions\Quotations\UpdateQuotationDraft;
use App\Enums\QuotationStatus;
use App\Enums\ReservationStatus;
use App\Enums\TransactionItemType;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Schemas\QuotationCreationForm;
use App\Models\FrameReservation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
                ->visible(fn (): bool => in_array($this->record->status, [QuotationStatus::Draft, QuotationStatus::Presented], true)
                    && $this->record->jobOrder === null)
                ->schema(QuotationCreationForm::components())
                ->fillForm(fn (): array => [
                    'valid_until' => $this->record->valid_until?->toDateString(),
                    'discount_amount' => (float) $this->record->discount_amount,
                    'notes' => $this->record->notes,
                    'items' => $this->record->items->map(fn ($item): array => [
                        'item_type' => match (true) {
                            filled($item->product_variant_id) => 'catalog',
                            filled($item->lens_category_id) => 'lens',
                            filled($item->service_id) => 'service',
                            $item->item_type === TransactionItemType::Product => 'custom_product',
                            default => 'custom_service',
                        },
                        'product_variant_id' => $item->product_variant_id,
                        'lens_category_id' => $item->lens_category_id,
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
                        app(UpdateQuotationDraft::class)->handle($this->record, $data);
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
                ->visible(fn (): bool => in_array($this->record->status, [QuotationStatus::Draft, QuotationStatus::Presented], true))
                ->schema(function (): array {
                    $serviceItems = $this->record->items()
                        ->where('item_type', TransactionItemType::Service)
                        ->get();

                    $hasProductItems = $this->record->productItems()->exists();

                    $activeReservations = FrameReservation::query()
                        ->where('patient_id', $this->record->patient_id)
                        ->whereIn('status', [ReservationStatus::Requested, ReservationStatus::Prepared, ReservationStatus::TriedOn])
                        ->with('items.variant.product')
                        ->get();

                    return [
                        CheckboxList::make('performed_service_item_ids')
                            ->label('Services to bill now')
                            ->helperText('Unselected services stay proposed — bill them later from "Bill Remaining Services".')
                            ->options($serviceItems->mapWithKeys(fn ($item): array => [
                                $item->id => "{$item->description} (₱".number_format((float) $item->amount, 2).')',
                            ]))
                            ->visible($serviceItems->isNotEmpty()),

                        Select::make('frame_reservation_id')
                            ->label('Frame Reservation')
                            ->helperText('Converts the reservation\'s already-held stock into this order instead of committing it a second time.')
                            ->options($activeReservations->mapWithKeys(fn (FrameReservation $reservation): array => [
                                $reservation->id => 'Reservation #'.$reservation->id.' — '.$reservation->items
                                    ->map(fn ($item): string => $item->variant?->product?->name ?? 'Unknown frame')
                                    ->implode(', ').' ('.Str::headline($reservation->status->value).')',
                            ]))
                            ->searchable()
                            ->nullable()
                            ->visible($hasProductItems && $activeReservations->isNotEmpty()),

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
                ->modalDescription('This creates the Optical Order from product lines and bills any selected services.')
                ->action(function (array $data): void {
                    $confirmer = auth()->user();

                    abort_unless($confirmer instanceof User, 403);

                    try {
                        $result = app(ConfirmQuotationSale::class)->handle(
                            quotation: $this->record,
                            confirmer: $confirmer,
                            performedServiceItemIds: array_map('intval', $data['performed_service_item_ids'] ?? []),
                            paymentDueDate: filled($data['payment_due_date'] ?? null) ? Carbon::parse($data['payment_due_date']) : null,
                            depositAmount: filled($data['deposit_amount'] ?? null) ? (float) $data['deposit_amount'] : null,
                            depositPaymentMethod: $data['deposit_payment_method'] ?? null,
                            depositReference: $data['deposit_reference'] ?? null,
                            frameReservationId: filled($data['frame_reservation_id'] ?? null) ? (int) $data['frame_reservation_id'] : null,
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
                ->label('Decline')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === QuotationStatus::Presented)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(RecordQuotationDecision::class)->handle($this->record, 'declined', auth()->user());
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
