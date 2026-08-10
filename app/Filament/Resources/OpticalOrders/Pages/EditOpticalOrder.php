<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Actions\BillingRecords\DispenseJobOrder;
use App\Actions\JobOrders\ApproveEyewearSpecification;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\JobOrders\VerifyEyewear;
use App\Actions\OpticalOrders\CancelOpticalOrder;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;

class EditOpticalOrder extends EditRecord
{
    protected static string $resource = OpticalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approveSpecification')
                ->label('Approve Specification')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->record->eyewearSpecification !== null
                    && ! $this->record->eyewearSpecification->isApproved()
                    && auth()->user()->isOptometrist())
                ->requiresConfirmation()
                ->modalHeading('Approve Eyewear Specification')
                ->modalDescription('Approve this corrective-eyewear specification. Only optometrists can approve.')
                ->modalSubmitActionLabel('Approve')
                ->action(function (): void {
                    try {
                        app(ApproveEyewearSpecification::class)->handle(
                            $this->record->eyewearSpecification,
                            auth()->user(),
                        );
                        Notification::make()->title('Specification approved')->success()->send();
                        $this->refreshFormData(['status']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot approve')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('verifyEyewear')
                ->label('Verify Eyewear')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === JobOrderStatus::InProgress
                    && $this->record->eyewearSpecification !== null
                    && $this->record->eyewearSpecification->isApproved()
                    && ! $this->record->eyewearSpecification->isVerified())
                ->requiresConfirmation()
                ->modalHeading('Verify Completed Eyewear')
                ->modalDescription('Confirm the completed eyewear matches the approved specification.')
                ->modalSubmitActionLabel('Verify')
                ->schema([
                    Textarea::make('verification_notes')
                        ->label('Notes')
                        ->nullable()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        app(VerifyEyewear::class)->handle(
                            $this->record,
                            auth()->user(),
                            $data['verification_notes'] ?? null,
                        );
                        Notification::make()->title('Eyewear verified')->success()->send();
                        $this->refreshFormData(['status']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot verify')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('start')
                ->label('Start')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === JobOrderStatus::Queued
                    && ($this->record->eyewearSpecification === null || $this->record->eyewearSpecification->isApproved()))
                ->requiresConfirmation()
                ->modalHeading('Start Processing')
                ->modalDescription('Begin processing this optical order.')
                ->modalSubmitActionLabel('Start')
                ->action(function (): void {
                    try {
                        app(UpdateJobOrderStatus::class)->handle($this->record, 'in_progress');
                        Notification::make()->title('Order started')->success()->send();
                        $this->refreshFormData(['status', 'started_at']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot start order')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('markReady')
                ->label('Mark Ready')
                ->icon('heroicon-o-check')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === JobOrderStatus::InProgress)
                ->requiresConfirmation()
                ->modalHeading('Mark Ready for Pickup')
                ->modalDescription('Mark this order as ready for patient pickup.')
                ->modalSubmitActionLabel('Mark Ready')
                ->schema([
                    TextInput::make('supplier_invoice_number')
                        ->label('Supplier Invoice Number')
                        ->default(fn (): ?string => $this->record->supplier_invoice_number)
                        ->required(fn (): bool => $this->record->uses_external_supplier)
                        ->maxLength(100),
                ])
                ->action(function (array $data): void {
                    try {
                        $this->record->update([
                            'supplier_invoice_number' => $data['supplier_invoice_number'],
                        ]);
                        app(UpdateJobOrderStatus::class)->handle($this->record, 'ready_for_dispensing');
                        Notification::make()->title('Order marked ready')->success()->send();
                        $this->refreshFormData(['status', 'supplier_invoice_number', 'ready_at']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot mark ready')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, [JobOrderStatus::Queued, JobOrderStatus::InProgress], true))
                ->schema([
                    Textarea::make('cancellation_reason')
                        ->label('Reason')
                        ->nullable(),
                ])
                ->requiresConfirmation()
                ->modalDescription('This will cancel the order and reverse any committed inventory.')
                ->action(function (array $data): void {
                    try {
                        app(CancelOpticalOrder::class)->handle(
                            $this->record,
                            $data['cancellation_reason'] ?? null,
                        );

                        $this->record->refresh();
                        Notification::make()->title('Order cancelled')->success()->send();
                        $this->refreshFormData(['status', 'cancelled_at']);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot cancel order')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('dispense')
                ->label('Dispense')
                ->icon('heroicon-o-shopping-bag')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === JobOrderStatus::ReadyForDispensing)
                ->requiresConfirmation()
                ->schema([
                    TextInput::make('recipient_name')
                        ->label('Recipient Name')
                        ->nullable()
                        ->maxLength(255),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->nullable()
                        ->maxLength(1000),
                    TextInput::make('initial_payment_amount')
                        ->label('Initial Payment')
                        ->numeric()
                        ->prefix('₱')
                        ->nullable(),
                    Select::make('initial_payment_method')
                        ->label('Payment Method')
                        ->options([
                            'cash' => 'Cash',
                            'gcash' => 'GCash',
                            'bank_transfer' => 'Bank Transfer',
                            'card' => 'Card',
                        ])
                        ->nullable()
                        ->visible(fn (Get $get): bool => filled($get('initial_payment_amount'))),
                ])
                ->action(function (array $data): void {
                    try {
                        app(DispenseJobOrder::class)->handle(
                            jobOrder: $this->record,
                            dispenser: auth()->user(),
                            recipientName: $data['recipient_name'] ?? null,
                            notes: $data['notes'] ?? null,
                            pickupPaymentAmount: $data['initial_payment_amount'] ?? null,
                            pickupPaymentMethod: $data['initial_payment_method'] ?? null,
                        );

                        Notification::make()->title('Order dispensed — billing record created')->success()->send();
                        $this->refreshFormData(['status', 'dispensed_at']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot dispense')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
