<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Actions\Invoices\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordPayment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, [
                    InvoiceStatus::Draft,
                    InvoiceStatus::Issued,
                    InvoiceStatus::PartiallyPaid,
                ], true))
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount')
                        ->required()
                        ->numeric()
                        ->prefix('PHP'),
                    Select::make('payment_method')
                        ->label('Method')
                        ->options([
                            'cash' => 'Cash',
                            'gcash' => 'GCash',
                            'bank_transfer' => 'Bank Transfer',
                            'credit_card' => 'Credit Card',
                            'check' => 'Check',
                        ])
                        ->required(),
                    TextInput::make('reference_number')
                        ->label('Reference #')
                        ->nullable(),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    app(RecordInvoicePayment::class)->handle(
                        invoice: $this->record,
                        amount: (float) $data['amount'],
                        paymentMethod: $data['payment_method'],
                        recorder: auth()->user(),
                        referenceNumber: $data['reference_number'] ?? null,
                        notes: $data['notes'] ?? null,
                    );
                    $this->refreshFormData(['status', 'amount_paid', 'balance_due']);
                }),
        ];
    }
}
