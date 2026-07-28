<?php

namespace App\Filament\Resources\BillingRecords\Pages;

use App\Actions\BillingRecords\CorrectBillingPayment;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\BillingRecords\VoidBillingRecord;
use App\Enums\BillingRecordStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class EditBillingRecord extends EditRecord
{
    protected static string $resource = BillingRecordResource::class;

    public function getTitle(): string
    {
        return $this->record->billing_record_number;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Billing Record Details')->schema([
                        Placeholder::make('billing_record_number')
                            ->label('Billing Record #')
                            ->content(fn (BillingRecord $record): string => $record->billing_record_number),
                        Placeholder::make('patient_name')
                            ->label('Patient')
                            ->content(fn (BillingRecord $record): string => $record->patient?->full_name ?? '—'),
                        Placeholder::make('job_order_number')
                            ->label('Job Order')
                            ->content(fn (BillingRecord $record): string => $record->jobOrder?->job_order_number ?? '—'),
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (BillingRecord $record): string => $record->status->getLabel()),
                    ])->columns(2),

                    Section::make('Payment History')->schema([
                        Placeholder::make('payments_list')
                            ->label('')
                            ->content(function (BillingRecord $record): string {
                                $payments = $record->payments()->orderByDesc('recorded_at')->get();

                                if ($payments->isEmpty()) {
                                    return 'No payments recorded.';
                                }

                                return $payments->map(function (BillingPayment $p): string {
                                    $status = $p->status === 'posted' ? '✓' : '✗';
                                    $method = ucfirst(str_replace('_', ' ', $p->payment_method));
                                    $amount = '₱'.number_format($p->amount, 2);
                                    $date = $p->recorded_at->format('M d, Y g:i A');
                                    $ref = $p->reference_number ? " ({$p->reference_number})" : '';

                                    return "{$status} {$amount} — {$method}{$ref} — {$date}";
                                })->implode("\n");
                            }),
                    ]),
                ]),

                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Financial Summary')->schema([
                        Placeholder::make('total_amount')
                            ->label('Total Amount')
                            ->content(fn (BillingRecord $record): string => '₱'.number_format($record->total_amount, 2)),
                        Placeholder::make('amount_paid')
                            ->label('Amount Paid')
                            ->content(fn (BillingRecord $record): string => '₱'.number_format($record->amount_paid, 2)),
                        Placeholder::make('balance_due')
                            ->label('Balance Due')
                            ->content(fn (BillingRecord $record): string => '₱'.number_format($record->balance_due, 2)),
                    ]),
                ]),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordPayment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, [
                    BillingRecordStatus::Unpaid,
                    BillingRecordStatus::PartiallyPaid,
                ], true))
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount')
                        ->required()
                        ->numeric()
                        ->prefix('₱'),
                    Select::make('payment_method')
                        ->label('Method')
                        ->options([
                            'cash' => 'Cash',
                            'gcash' => 'GCash',
                            'bank_transfer' => 'Bank Transfer',
                            'card' => 'Card',
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
                    try {
                        app(RecordBillingPayment::class)->handle(
                            billingRecord: $this->record,
                            amount: (float) $data['amount'],
                            paymentMethod: $data['payment_method'],
                            recorder: auth()->user(),
                            referenceNumber: $data['reference_number'] ?? null,
                            notes: $data['notes'] ?? null,
                        );

                        Notification::make()->title('Payment recorded')->success()->send();
                        $this->refreshFormData(['status', 'amount_paid', 'balance_due']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot record payment')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('correctPayment')
                ->label('Correct Payment')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->isAdmin() === true
                    && $this->record->payments()->where('status', 'posted')->exists())
                ->schema([
                    Select::make('original_payment_id')
                        ->label('Payment to Correct')
                        ->options(fn () => $this->record->payments()
                            ->where('status', 'posted')
                            ->pluck('amount', 'id')
                            ->mapWithKeys(fn ($amount, $id) => [$id => '₱'.number_format($amount, 2).' — '])
                            ->toArray())
                        ->required(),
                    TextInput::make('new_amount')
                        ->label('Corrected Amount')
                        ->required()
                        ->numeric()
                        ->prefix('₱'),
                    TextInput::make('reference_number')
                        ->label('New Reference #')
                        ->nullable(),
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        $originalPayment = BillingPayment::query()->findOrFail($data['original_payment_id']);

                        app(CorrectBillingPayment::class)->handle(
                            originalPayment: $originalPayment,
                            newAmount: (float) $data['new_amount'],
                            reason: $data['reason'],
                            corrector: auth()->user(),
                            newReferenceNumber: $data['reference_number'] ?? null,
                        );

                        Notification::make()->title('Payment corrected')->success()->send();
                        $this->refreshFormData(['status', 'amount_paid', 'balance_due']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot correct payment')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('voidRecord')
                ->label('Void Billing Record')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->isAdmin() === true
                    && $this->record->status !== BillingRecordStatus::Voided)
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        app(VoidBillingRecord::class)->handle(
                            billingRecord: $this->record,
                            reason: $data['reason'],
                            voider: auth()->user(),
                        );

                        Notification::make()->title('Billing record voided')->success()->send();
                        $this->refreshFormData(['status', 'voided_by', 'voided_at', 'void_reason']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot void')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
