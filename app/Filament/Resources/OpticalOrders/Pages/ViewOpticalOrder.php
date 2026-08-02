<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Actions\OpticalOrders\AcceptAndStartOpticalOrder;
use App\Actions\OpticalOrders\CancelOpticalOrder;
use App\Actions\Quotations\PresentQuotation;
use App\Actions\Quotations\RecordQuotationDecision;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ViewOpticalOrder extends ViewRecord
{
    protected static string $resource = OpticalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Edit action (Draft or Presented)
            Action::make('edit')
                ->label('Edit')
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->url(fn () => OpticalOrderResource::getUrl('edit', ['record' => $this->record]))
                ->visible(fn () => in_array($this->record->status, [QuotationStatus::Draft, QuotationStatus::Presented], true)),

            // Share Estimate (Draft -> Presented)
            Action::make('shareEstimate')
                ->label('Share Estimate')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->visible(fn () => $this->record->status === QuotationStatus::Draft)
                ->requiresConfirmation()
                ->modalHeading('Share Estimate with Patient')
                ->modalDescription('This will make the estimate visible to the patient through their app.')
                ->action(function () {
                    app(PresentQuotation::class)->handle(
                        $this->record,
                        auth()->user(),
                    );

                    $this->record->refresh();
                    Notification::make()->title('Estimate shared with patient')->success()->send();
                }),

            // Confirm Sale (Draft or Presented)
            Action::make('confirmSale')
                ->label('Confirm Sale')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => in_array($this->record->status, [QuotationStatus::Draft, QuotationStatus::Presented], true))
                ->schema([
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
                        ->visible(fn (callable $get) => filled($get('deposit_amount'))),

                    TextInput::make('deposit_reference')
                        ->label('Reference Number')
                        ->nullable()
                        ->visible(fn (callable $get) => filled($get('deposit_amount'))),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(AcceptAndStartOpticalOrder::class)->handle(
                            $this->record,
                            paymentDueDate: $data['payment_due_date'] ? Carbon::parse($data['payment_due_date']) : null,
                            depositAmount: $data['deposit_amount'] ? (float) $data['deposit_amount'] : null,
                            depositPaymentMethod: $data['deposit_payment_method'] ?? null,
                            depositReference: $data['deposit_reference'] ?? null,
                        );

                        $this->record->refresh();
                        Notification::make()
                            ->title('Sale confirmed')
                            ->body("Job Order: {$result['job_order']->job_order_number}")
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot confirm sale')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            // Decline (Presented only)
            Action::make('decline')
                ->label('Decline')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === QuotationStatus::Presented)
                ->requiresConfirmation()
                ->modalHeading('Decline Estimate')
                ->modalDescription('This will mark the estimate as declined.')
                ->action(function () {
                    app(RecordQuotationDecision::class)->handle(
                        $this->record,
                        'declined',
                        auth()->user(),
                    );

                    $this->record->refresh();
                    Notification::make()->title('Estimate declined')->success()->send();
                }),

            // Cancel Order (Queued or In Progress)
            Action::make('cancelOrder')
                ->label('Cancel Order')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn () => $this->record->jobOrder !== null
                    && in_array($this->record->jobOrder->status, [JobOrderStatus::Queued, JobOrderStatus::InProgress], true))
                ->schema([
                    Textarea::make('cancellation_reason')
                        ->label('Reason')
                        ->nullable(),
                ])
                ->requiresConfirmation()
                ->modalHeading('Cancel Order')
                ->modalDescription('This will cancel the job order and reverse any committed inventory.')
                ->action(function (array $data): void {
                    try {
                        app(CancelOpticalOrder::class)->handle(
                            $this->record->jobOrder,
                            $data['cancellation_reason'] ?? null,
                        );

                        $this->record->refresh();
                        Notification::make()->title('Order cancelled')->success()->send();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot cancel order')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
