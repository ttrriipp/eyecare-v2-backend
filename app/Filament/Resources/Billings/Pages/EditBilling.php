<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Billing\AddServiceToBilling;
use App\Actions\Billing\RecalculateBillingBalance;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Billings\BillingResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Appointment;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\BillingStatus;
use App\Models\DiscountType;
use App\Models\PaymentStatus;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class EditBilling extends EditRecord
{
    protected static string $resource = BillingResource::class;

    private ?int $pendingDiscountTypeId = null;

    private ?string $pendingCustomDiscountAmount = null;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ────────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make()->schema([
                        Placeholder::make('billing_number')
                            ->label('Billing #')
                            ->content(fn (): string => $this->getRecord()?->billing_number ?? '—'),
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (): string => ucwords(str_replace('_', ' ', $this->getRecord()?->status?->name ?? ''))),
                        Placeholder::make('customer')
                            ->label('Patient')
                            ->content(fn (): string => $this->getRecord()?->customer?->name ?? '—'),
                        Textarea::make('notes')
                            ->label('Staff Notes')
                            ->disabled(fn (): bool => ! $this->isEditable())
                            ->columnSpanFull(),
                    ])->columns(2),

                    Section::make('Linked Records')
                        ->headerActions([
                            Action::make('create_order')
                                ->label('Create Order')
                                ->icon('heroicon-o-shopping-bag')
                                ->visible(fn (): bool => $this->isEditable())
                                ->url(fn (): string => OrderResource::getUrl('create', ['billing_id' => $this->getRecord()->id])),
                        ])
                        ->columns(2)
                        ->schema([
                            Placeholder::make('order_link')
                                ->label('Order')
                                ->content(function (): HtmlString|string {
                                    $billing = $this->getRecord();
                                    if (! $billing?->order_id) {
                                        return '—';
                                    }
                                    $url = OrderResource::getUrl('edit', ['record' => $billing->order_id]);

                                    return new HtmlString("<a href=\"{$url}\" class=\"text-primary-600 hover:underline\">{$billing->order->order_number}</a>");
                                }),
                            Select::make('appointment_id')
                                ->label('Appointment')
                                ->options(function (): array {
                                    $billing = $this->getRecord();
                                    if (! $billing?->customer_id) {
                                        return [];
                                    }

                                    return Appointment::query()
                                        ->with('visitReason')
                                        ->where('customer_id', $billing->customer_id)
                                        ->latest('scheduled_at')
                                        ->get()
                                        ->mapWithKeys(fn (Appointment $appointment): array => [
                                            $appointment->id => "#{$appointment->id} — {$appointment->scheduled_at->format('M j, Y g:i A')} — ".($appointment->visitReason?->name ?? 'Appointment'),
                                        ])
                                        ->toArray();
                                })
                                ->nullable()
                                ->placeholder('No appointment')
                                ->searchable()
                                ->disabled(fn (): bool => ! $this->isEditable())
                                ->helperText(function (): ?HtmlString {
                                    $appointmentId = $this->getRecord()?->appointment_id;
                                    if (! $appointmentId) {
                                        return null;
                                    }

                                    $url = AppointmentResource::getUrl('edit', ['record' => $appointmentId]);

                                    return new HtmlString("<a href=\"{$url}\" class=\"text-primary-600 hover:underline\">Open appointment</a>");
                                }),
                        ]),
                ]),

                // ── Sidebar (1/3) ─────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make()->schema([
                        Placeholder::make('issued_at')
                            ->label('Issued')
                            ->content(fn (): string => $this->getRecord()?->issued_at?->format('M j, Y') ?? '—'),
                        Placeholder::make('amount_paid')
                            ->label('Amount Paid')
                            ->content(fn (): string => '₱'.number_format((float) ($this->getRecord()?->amount_paid ?? 0), 2)),
                        Placeholder::make('balance_due')
                            ->label('Balance Due')
                            ->content(fn (): string => '₱'.number_format((float) ($this->getRecord()?->balance_due ?? 0), 2)),
                    ]),
                ]),
            ]),

            // ── Order Items (read-only, only when linked to an order) ─
            Section::make('Order Items')
                ->visible(fn (): bool => $this->getRecord()?->productItems()->exists() ?? false)
                ->schema([
                    Repeater::make('order_items_display')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('description')
                                ->label('Description')
                                ->disabled()
                                ->columnSpan(2),
                            TextInput::make('quantity')
                                ->label('Qty')
                                ->disabled()
                                ->columnSpan(1),
                            TextInput::make('unit_price')
                                ->label('Unit Price')
                                ->prefix('₱')
                                ->disabled()
                                ->columnSpan(1),
                            TextInput::make('amount')
                                ->label('Amount')
                                ->prefix('₱')
                                ->disabled()
                                ->columnSpan(1),
                        ])
                        ->columns(5)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->dehydrated(false),
                ]),

            // ── Services ─────────────────────────────────────────────
            Section::make('Services')
                ->schema([
                    Repeater::make('existing_services')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('description')
                                ->label('Service')
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpan(2),
                            TextInput::make('amount')
                                ->label('Amount')
                                ->prefix('₱')
                                ->numeric()
                                ->minValue(0.01)
                                ->required()
                                ->disabled(fn (): bool => ! $this->isEditable())
                                ->dehydrated()
                                ->columnSpan(1),
                            Hidden::make('id'),
                            Hidden::make('service_record_id'),
                        ])
                        ->columns(3)
                        ->addable(false)
                        ->deletable(fn (): bool => $this->isEditable())
                        ->reorderable(false)
                        ->dehydrated(false),

                    Repeater::make('new_services')
                        ->label('Add Services')
                        ->hidden(fn (): bool => ! $this->isEditable())
                        ->schema([
                            Select::make('service_id')
                                ->label('Service')
                                ->options(fn () => Service::query()->active()->pluck('name', 'id'))
                                ->required()
                                ->searchable()
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Get $get, Set $set, ?int $state): void {
                                    if ($state && empty($get('amount'))) {
                                        $price = Service::find($state)?->price;
                                        if ($price !== null) {
                                            $set('amount', number_format((float) $price, 2, '.', ''));
                                        }
                                    }
                                }),
                            TextInput::make('amount')
                                ->label('Amount')
                                ->prefix('₱')
                                ->numeric()
                                ->minValue(0.01)
                                ->columnSpan(1),
                            Select::make('staff_id')
                                ->label('Performed by')
                                ->options(fn () => User::query()
                                    ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
                                    ->pluck('name', 'id')
                                )
                                ->required()
                                ->default(fn () => auth()->id())
                                ->columnSpan(2),
                            DateTimePicker::make('performed_at')
                                ->label('Performed at')
                                ->required()
                                ->default(now())
                                ->columnSpan(1),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel('Add another service')
                        ->deleteAction(fn (Action $action) => $action->iconButton())
                        ->dehydrated(false),
                ]),

            // ── Billing Total ─────────────────────────────────────────
            Section::make('Billing Total')
                ->schema([
                    Grid::make(3)->schema([
                        Placeholder::make('subtotal_display')
                            ->label('Subtotal')
                            ->content(fn (): string => '₱'.number_format((float) ($this->getRecord()?->subtotal ?? 0), 2)),
                        Placeholder::make('discount_display')
                            ->label('Discount')
                            ->content(fn (): string => '₱'.number_format((float) ($this->getRecord()?->discount_amount ?? 0), 2)),
                        Placeholder::make('total_display')
                            ->label('Total')
                            ->content(fn (): string => '₱'.number_format((float) ($this->getRecord()?->total_amount ?? 0), 2)),
                    ]),
                    Grid::make(3)->schema([
                        Select::make('discount_type_id')
                            ->label('Discount Type')
                            ->options(fn (): array => $this->discountTypeOptions())
                            ->nullable()
                            ->live()
                            ->placeholder('No discount')
                            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                            ->disabled(fn (): bool => ! $this->isEditable())
                            ->columnSpan(2),
                        TextInput::make('custom_discount_amount')
                            ->label('Custom Amount')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₱')
                            ->visible(fn (Get $get): bool => $this->isFixedDiscount($get('discount_type_id')))
                            ->disabled(fn (): bool => ! $this->isEditable())
                            ->dehydrated(false)
                            ->columnSpan(1),
                    ]),
                ]),
        ]);
    }

    private function isEditable(): bool
    {
        $billing = $this->getRecord();

        return $billing !== null && in_array($billing->status?->name, ['issued', 'partially_paid']);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $billing = $this->getRecord()->load('items');

        $data['custom_discount_amount'] = $billing->discount_amount;

        $data['order_items_display'] = $billing->productItems
            ->map(fn (BillingItem $item): array => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'amount' => (string) $item->amount,
            ])->values()->toArray();

        $data['existing_services'] = $billing->serviceItems
            ->map(fn (BillingItem $item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'amount' => (string) $item->amount,
                'service_record_id' => $item->service_record_id,
            ])->values()->toArray();

        return $data;
    }

    protected function beforeSave(): void
    {
        $billing = $this->getRecord();

        if (! in_array($billing->status->name, ['issued', 'partially_paid'])) {
            return;
        }

        // Sync edits and handle deletions on existing service items
        $submitted = collect($this->data['existing_services'] ?? [])->keyBy('id');
        $existing = $billing->serviceItems()->get()->keyBy('id');

        // Items removed from the repeater — delete them
        foreach ($existing as $id => $item) {
            if (! $submitted->has($id)) {
                app(CreateAuditLog::class)->handle($billing, 'billing.service_item_removed', [
                    'item_description' => $item->description,
                    'amount' => (string) $item->amount,
                ]);
                $item->serviceRecord?->delete();
                $item->delete();
            }
        }

        // Items still present — update amount if changed
        foreach ($submitted as $id => $row) {
            $item = $existing->get($id);
            if (! $item) {
                continue;
            }
            $newAmount = (string) $row['amount'];
            if (bccomp($newAmount, (string) $item->amount, 2) !== 0) {
                $oldAmount = (string) $item->amount;

                $item->update(['unit_price' => $newAmount, 'amount' => $newAmount]);
                $item->serviceRecord?->update(['amount' => $newAmount]);
                app(CreateAuditLog::class)->handle($billing, 'billing.service_item_edited', [
                    'item_description' => $item->description,
                    'old_amount' => $oldAmount,
                    'new_amount' => $newAmount,
                ]);
            }
        }

        // Add new services queued in the new_services repeater
        foreach ($this->data['new_services'] ?? [] as $row) {
            if (empty($row['service_id'])) {
                continue;
            }
            $payload = [
                'service_id' => $row['service_id'],
                'staff_id' => $row['staff_id'] ?? auth()->id(),
                'performed_at' => $row['performed_at'] ?? now(),
                'appointment_id' => $this->data['appointment_id'] ?? $billing->appointment_id,
            ];
            if (filled($row['amount'] ?? null)) {
                $payload['amount'] = $row['amount'];
            }
            app(AddServiceToBilling::class)->handle($billing, $payload);
        }
    }

    protected function afterSave(): void
    {
        $billing = $this->getRecord()->fresh();

        if (! in_array($billing->status->name, ['issued', 'partially_paid'])) {
            return;
        }

        $newSubtotal = (string) $billing->items()->sum('amount');
        $discountTypeId = $this->discountTypeIdForSave($billing);
        $discountAmount = $this->discountAmountForSave($billing, $newSubtotal, $discountTypeId);
        $newTotal = bcsub((string) $newSubtotal, $discountAmount, 2);

        $billing->update([
            'discount_type_id' => $discountTypeId,
            'discount_amount' => $discountAmount,
            'subtotal' => $newSubtotal,
            'total_amount' => $newTotal,
        ]);

        app(RecalculateBillingBalance::class)->handle($billing->fresh());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('void_billing')
                ->label('Void Billing')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Void this billing?')
                ->modalDescription(function (): string {
                    $billing = $this->getRecord();
                    $postedPayments = $billing->payments()
                        ->whereHas('status', fn ($q) => $q->where('name', 'posted'))
                        ->sum('amount');

                    if ((float) $postedPayments > 0) {
                        return 'This billing has ₱'.number_format((float) $postedPayments, 2).' in posted payments. Voiding will mark those payments as voided and cannot be undone.';
                    }

                    return 'This will void the billing. This action is logged and cannot be undone.';
                })
                ->visible(fn (): bool => in_array($this->getRecord()->status->name, ['issued', 'partially_paid']) && (auth()->user()?->isAdmin() ?? false))
                ->action(function (): void {
                    $billing = $this->getRecord();
                    $payments = $billing->payments()
                        ->whereHas('status', fn ($q) => $q->where('name', 'posted'))
                        ->with('paymentMethod')
                        ->get();

                    $auditMetadata = [
                        'billing_number' => $billing->billing_number,
                        'total_amount' => (string) $billing->total_amount,
                        'amount_paid' => (string) $billing->amount_paid,
                        'balance_due' => (string) $billing->balance_due,
                        'payments_voided' => $payments->map(fn ($p) => [
                            'id' => $p->id,
                            'amount' => (string) $p->amount,
                            'method' => $p->paymentMethod?->name,
                            'paid_at' => $p->paid_at?->toDateTimeString(),
                        ])->all(),
                        'line_items' => $billing->items->map(fn ($item) => [
                            'description' => $item->description,
                            'quantity' => $item->quantity,
                            'unit_price' => (string) $item->unit_price,
                            'amount' => (string) $item->amount,
                        ])->all(),
                    ];

                    $voidedPaymentStatus = PaymentStatus::query()->where('name', 'voided')->firstOrFail();
                    $billing->payments()
                        ->whereHas('status', fn ($q) => $q->where('name', 'posted'))
                        ->update(['payment_status_id' => $voidedPaymentStatus->id]);

                    $voidedBillingStatus = BillingStatus::query()->where('name', 'voided')->firstOrFail();
                    $billing->update(['billing_status_id' => $voidedBillingStatus->id]);

                    app(CreateAuditLog::class)->handle($billing, 'voided', $auditMetadata);
                })
                ->successNotificationTitle('Billing voided'),

            ActionGroup::make([
                Action::make('download_receipt')
                    ->label('Download Receipt (A4 PDF)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn () => route('pdf.billing', $this->getRecord()))
                    ->openUrlInNewTab(),
                Action::make('print_thermal')
                    ->label('Print Receipt (Thermal)')
                    ->icon('heroicon-o-printer')
                    ->url(fn () => route('thermal.billing', $this->getRecord()))
                    ->openUrlInNewTab(),
            ])
                ->label('Print / Download')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->button(),
        ];
    }

    /** @return array<int, string> */
    private function discountTypeOptions(): array
    {
        return DiscountType::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (DiscountType $discountType): array => [
                $discountType->id => $discountType->type === 'percentage'
                    ? "{$discountType->name} ({$discountType->value}%)"
                    : $discountType->name,
            ])
            ->toArray();
    }

    private function isFixedDiscount(mixed $discountTypeId): bool
    {
        return filled($discountTypeId)
            && DiscountType::query()->whereKey($discountTypeId)->where('type', 'fixed')->exists();
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $billing = $this->getRecord();

        if (auth()->user()?->isAdmin() ?? false) {
            $this->pendingDiscountTypeId = filled($data['discount_type_id'] ?? null)
                ? (int) $data['discount_type_id']
                : null;

            $this->pendingCustomDiscountAmount = filled($data['custom_discount_amount'] ?? null)
                ? (string) $data['custom_discount_amount']
                : null;
        }

        if (! in_array($billing->status->name, ['issued', 'partially_paid'])) {
            return [
                'appointment_id' => $billing->appointment_id,
                'discount_type_id' => $billing->discount_type_id,
                'notes' => $billing->notes,
            ];
        }

        $saveData = [
            'appointment_id' => $data['appointment_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if (auth()->user()?->isAdmin() ?? false) {
            $saveData['discount_type_id'] = $this->pendingDiscountTypeId;
        }

        return $saveData;
    }

    private function discountTypeIdForSave(Billing $billing): ?int
    {
        if (! (auth()->user()?->isAdmin() ?? false)) {
            return $billing->discount_type_id;
        }

        return $this->pendingDiscountTypeId ?? $billing->discount_type_id;
    }

    private function discountAmountForSave(Billing $billing, string $subtotal, ?int $discountTypeId): string
    {
        if (! (auth()->user()?->isAdmin() ?? false)) {
            return (string) ($billing->discount_amount ?? '0.00');
        }

        if (! $discountTypeId) {
            return '0.00';
        }

        $discountType = DiscountType::query()->findOrFail($discountTypeId);

        if ($discountType->type === 'percentage') {
            return bcmul($subtotal, bcdiv((string) $discountType->value, '100', 4), 2);
        }

        return (string) ($this->pendingCustomDiscountAmount ?? $discountType->value);
    }
}
