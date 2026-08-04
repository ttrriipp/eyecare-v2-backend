<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Actions\Quotations\ConfirmQuotationSale;
use App\Actions\Quotations\PresentQuotation;
use App\Actions\Quotations\RecordQuotationDecision;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
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

            Action::make('confirmSale')
                ->label('Confirm Sale')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, [QuotationStatus::Draft, QuotationStatus::Presented, QuotationStatus::Accepted], true)
                    && $this->record->jobOrder === null)
                ->schema(function (): array {
                    $serviceItems = $this->record->items()
                        ->where('item_type', TransactionItemType::Service)
                        ->get();

                    return [
                        CheckboxList::make('performed_service_item_ids')
                            ->label('Services to bill now')
                            ->helperText('Unselected services remain proposed and can be billed later.')
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
