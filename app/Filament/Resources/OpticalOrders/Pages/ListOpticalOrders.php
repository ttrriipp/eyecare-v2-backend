<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Actions\OpticalOrders\CreateDirectOpticalOrder;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\OpticalOrders\Widgets\OpticalOrderStatsWidget;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ListOpticalOrders extends ListRecords
{
    protected static string $resource = OpticalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newDirectOrder')
                ->label('New Direct Order')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalHeading('New Direct Order')
                ->modalDescription('Sell product items with no prior Quotation.')
                ->schema([
                    Select::make('patient_id')
                        ->label('Patient')
                        ->options(fn (): array => Patient::query()
                            ->get()
                            ->mapWithKeys(fn (Patient $patient): array => [$patient->id => $patient->full_name])
                            ->all())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live(),

                    Select::make('prescription_id')
                        ->label('Corrective Prescription')
                        ->helperText('Required only if the order includes lens package items.')
                        ->options(fn (Get $get): array => filled($get('patient_id'))
                            ? Prescription::query()
                                ->where('patient_id', $get('patient_id'))
                                ->whereDoesntHave('nextPrescription')
                                ->get()
                                ->mapWithKeys(fn (Prescription $prescription): array => [
                                    $prescription->id => $prescription->prescription_number,
                                ])
                                ->all()
                            : [])
                        ->nullable()
                        ->searchable()
                        ->visible(fn (Get $get): bool => filled($get('patient_id'))),

                    Repeater::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('item_type')
                                ->label('Item Type')
                                ->options([
                                    'catalog' => 'Catalog Item',
                                    'lens' => 'Lens Category',
                                    'lens_option' => 'Lens Option',
                                    'custom' => 'Custom Item',
                                ])
                                ->default('catalog')
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('product_variant_id', null);
                                    $set('lens_category_id', null);
                                    $set('lens_option_id', null);
                                    $set('description', null);
                                    $set('unit_price', null);
                                }),

                            Select::make('product_variant_id')
                                ->label('Catalog Item')
                                ->options(fn (): array => ProductVariant::query()
                                    ->with('product')
                                    ->active()
                                    ->whereHas('product', fn (Builder $q): Builder => $q->where('is_active', true))
                                    ->orderBy('sku')
                                    ->get()
                                    ->mapWithKeys(fn (ProductVariant $variant): array => [
                                        $variant->id => "{$variant->product->name} — {$variant->name} ({$variant->sku})",
                                    ])
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->required(fn (Get $get): bool => $get('item_type') === 'catalog')
                                ->visible(fn (Get $get): bool => $get('item_type') === 'catalog')
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    $variant = ProductVariant::query()->with('product')->find($state);

                                    if ($variant === null) {
                                        return;
                                    }

                                    $set('description', "{$variant->product->name} — {$variant->name}");
                                    $set('unit_price', $variant->price);
                                    $set('line_total', number_format(
                                        ((float) ($get('quantity') ?? 1)) * ((float) $variant->price),
                                        2,
                                    ));
                                }),

                            Select::make('lens_category_id')
                                ->label('Lens Category')
                                ->options(fn (): array => LensCategory::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->required(fn (Get $get): bool => $get('item_type') === 'lens')
                                ->visible(fn (Get $get): bool => $get('item_type') === 'lens')
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    $lensCategory = LensCategory::query()->find($state);

                                    if ($lensCategory === null) {
                                        return;
                                    }

                                    $set('description', $lensCategory->name);

                                    if ($lensCategory->price !== null) {
                                        $set('unit_price', $lensCategory->price);
                                        $set('line_total', number_format(
                                            ((float) ($get('quantity') ?? 1)) * ((float) $lensCategory->price),
                                            2,
                                        ));
                                    }
                                }),

                            Select::make('lens_option_id')
                                ->label('Lens Option')
                                ->options(fn (): array => LensOption::query()
                                    ->active()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->required(fn (Get $get): bool => $get('item_type') === 'lens_option')
                                ->visible(fn (Get $get): bool => $get('item_type') === 'lens_option')
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    $lensOption = LensOption::query()->active()->find($state);

                                    if ($lensOption === null) {
                                        return;
                                    }

                                    $set('description', $lensOption->name);
                                    $set('unit_price', $lensOption->price);
                                    $set('line_total', number_format(
                                        ((float) ($get('quantity') ?? 1)) * ((float) $lensOption->price),
                                        2,
                                    ));
                                }),

                            TextInput::make('description')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),

                            TextInput::make('quantity')
                                ->required()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(999)
                                ->default(1)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Set $set, Get $get): void {
                                    $set('line_total', number_format(
                                        ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                        2,
                                    ));
                                }),

                            TextInput::make('unit_price')
                                ->required()
                                ->numeric()
                                ->minValue(0)
                                ->prefix('₱')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Set $set, Get $get): void {
                                    $set('line_total', number_format(
                                        ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                        2,
                                    ));
                                }),

                            TextInput::make('line_total')
                                ->label('Line Total')
                                ->prefix('₱')
                                ->disabled()
                                ->dehydrated(false),
                        ])
                        ->columns(3)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Add Item'),

                    Placeholder::make('order_total')
                        ->label('Total')
                        ->content(function (Get $get): string {
                            $total = collect($get('items') ?? [])->sum(
                                fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                                    * ((float) ($item['unit_price'] ?? 0)),
                            );

                            return '₱'.number_format($total, 2);
                        }),

                    Grid::make(2)->schema([
                        Select::make('fulfillment_mode')
                            ->label('Fulfillment')
                            ->options([
                                'immediate' => 'Complete sale now',
                                'prepared' => 'Prepare for pickup',
                            ])
                            ->default('prepared')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                if ($state !== 'immediate') {
                                    return;
                                }

                                $total = collect($get('items') ?? [])->sum(
                                    fn (array $item): float => ((float) ($item['quantity'] ?? 0)) * ((float) ($item['unit_price'] ?? 0)),
                                );

                                $set('deposit_amount', $total > 0 ? $total : null);
                            }),

                        Toggle::make('uses_external_supplier')
                            ->label('External lab/supplier work')
                            ->visible(fn (Get $get): bool => $get('fulfillment_mode') === 'prepared')
                            ->default(false),
                    ]),

                    TextInput::make('recipient_name')
                        ->label('Recipient Name')
                        ->visible(fn (Get $get): bool => $get('fulfillment_mode') === 'immediate')
                        ->maxLength(255),

                    DatePicker::make('payment_due_date')
                        ->label('Payment Due Date')
                        ->native(false)
                        ->minDate(today())
                        ->nullable(),

                    Grid::make(2)->schema([
                        TextInput::make('deposit_amount')
                            ->label(fn (Get $get): string => $get('fulfillment_mode') === 'immediate' ? 'Payment Amount' : 'Initial Deposit')
                            ->helperText(fn (Get $get): ?string => $get('fulfillment_mode') === 'immediate'
                                ? 'Defaults to the full order total. Enter a lower amount to leave a balance due.'
                                : null)
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
                    ]),

                    TextInput::make('deposit_reference')
                        ->label('Reference Number')
                        ->nullable()
                        ->visible(fn (Get $get): bool => filled($get('deposit_amount'))),
                ])
                ->action(function (array $data): void {
                    $creator = auth()->user();

                    abort_unless($creator instanceof User, 403);

                    $patient = Patient::query()->findOrFail($data['patient_id']);
                    $prescription = filled($data['prescription_id'] ?? null)
                        ? Prescription::query()->find($data['prescription_id'])
                        : null;

                    try {
                        $result = app(CreateDirectOpticalOrder::class)->handle(
                            patient: $patient,
                            creator: $creator,
                            items: $data['items'],
                            fulfillmentMode: $data['fulfillment_mode'] ?? 'prepared',
                            usesExternalSupplier: $data['uses_external_supplier'] ?? false,
                            prescription: $prescription,
                            paymentDueDate: filled($data['payment_due_date'] ?? null) ? Carbon::parse($data['payment_due_date']) : null,
                            depositAmount: filled($data['deposit_amount'] ?? null) ? (float) $data['deposit_amount'] : null,
                            depositPaymentMethod: $data['deposit_payment_method'] ?? null,
                            depositReference: $data['deposit_reference'] ?? null,
                            recipientName: $data['recipient_name'] ?? null,
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot create order')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Order created')
                        ->body("Order: {$result['job_order']->job_order_number}")
                        ->success()
                        ->send();

                    $this->redirect(OpticalOrderResource::getUrl('edit', [
                        'record' => $result['job_order'],
                    ]));
                }),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'confirmed' => Tab::make('Confirmed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::Queued)),

            'production' => Tab::make('Processing')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::InProgress)),

            'ready' => Tab::make('Ready for Pickup')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::ReadyForDispensing)),

            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::Dispensed)),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobOrderStatus::Cancelled)),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OpticalOrderStatsWidget::class,
        ];
    }
}
