<?php

namespace App\Filament\Resources\BillingRecords\Pages;

use App\Actions\BillingRecords\VoidBillingRecord;
use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Models\BillingRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

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
                            ->label('Optical Order')
                            ->content(fn (BillingRecord $record): string => $record->jobOrder?->job_order_number ?? '—'),
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (BillingRecord $record): string => $record->status->getLabel())
                            ->badge()
                            ->color(fn (BillingRecord $record): string => match ($record->status) {
                                BillingRecordStatus::Unpaid => 'gray',
                                BillingRecordStatus::PartiallyPaid => 'warning',
                                BillingRecordStatus::Paid => 'success',
                                BillingRecordStatus::Voided => 'danger',
                            }),
                    ])->columns(2),

                    Section::make('Items / Charges')->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Description'),
                                TableColumn::make('Source'),
                                TableColumn::make('Quantity'),
                                TableColumn::make('Unit Price'),
                                TableColumn::make('Amount'),
                            ])
                            ->schema([
                                TextEntry::make('description')
                                    ->hiddenLabel()
                                    ->wrap(),
                                TextEntry::make('source_kind')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->formatStateUsing(fn (BillingItemSourceKind $state): string => Str::headline($state->value)),
                                TextEntry::make('quantity')
                                    ->hiddenLabel(),
                                TextEntry::make('unit_price')
                                    ->hiddenLabel()
                                    ->money('PHP'),
                                TextEntry::make('amount')
                                    ->hiddenLabel()
                                    ->money('PHP'),
                            ])
                            ->placeholder('No charges recorded.'),
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
                        Placeholder::make('payment_due_date')
                            ->label('Payment Due Date')
                            ->content(fn (BillingRecord $record): string => $record->payment_due_date?->format('M j, Y') ?? 'Not set'),
                        Placeholder::make('overdue_status')
                            ->label('Status')
                            ->content(fn (BillingRecord $record): string => $record->isOverdue() ? 'Overdue' : 'Current')
                            ->badge()
                            ->color(fn (BillingRecord $record): string => $record->isOverdue() ? 'danger' : 'success'),
                    ]),
                ]),
            ]),
        ]);
    }

    #[On('billing-payment-updated')]
    public function refreshBillingRecordSummary(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['status', 'amount_paid', 'balance_due']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updateDueDate')
                ->label('Update Due Date')
                ->icon('heroicon-o-calendar')
                ->color('info')
                ->visible(fn (): bool => in_array($this->record->status, [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid], true))
                ->schema([
                    DatePicker::make('payment_due_date')
                        ->label('Payment Due Date')
                        ->required()
                        ->native(false)
                        ->minDate(today())
                        ->default(fn () => $this->record->payment_due_date ?? today()->addMonth()),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'payment_due_date' => Carbon::parse($data['payment_due_date']),
                    ]);

                    Notification::make()->title('Due date updated')->success()->send();
                    $this->refreshFormData(['payment_due_date']);
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
