<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\LensType;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make()->schema([
                        TextInput::make('order_number')
                            ->label('Number')
                            ->disabled()
                            ->dehydrated(),
                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabledOn('edit')
                            ->dehydrated()
                            ->createOptionForm([
                                TextInput::make('name')->required(),
                                TextInput::make('phone')->required()->tel(),
                                TextInput::make('email')->email()->nullable(),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                return User::create([
                                    'name' => $data['name'],
                                    'phone' => $data['phone'],
                                    'email' => $data['email'] ?? null,
                                    'password' => null,
                                    'role_id' => Role::query()->where('name', 'customer')->value('id'),
                                ])->getKey();
                            }),
                        ToggleButtons::make('order_status_id')
                            ->label('Status')
                            ->options(function (?Order $record): array {
                                if (! $record) {
                                    return [];
                                }

                                $order = ['requested', 'confirmed', 'preparing', 'ready_for_pickup', 'completed', 'cancelled'];

                                $transitions = [
                                    'requested' => ['confirmed', 'cancelled'],
                                    'confirmed' => ['preparing', 'cancelled'],
                                    'preparing' => ['ready_for_pickup', 'cancelled'],
                                    'ready_for_pickup' => ['completed', 'cancelled'],
                                    'completed' => [],
                                    'cancelled' => [],
                                ];

                                $currentName = $record->status->name;
                                $allowed = $transitions[$currentName] ?? [];
                                $visible = [$currentName, ...$allowed];

                                return OrderStatus::query()
                                    ->whereIn('name', $visible)
                                    ->get()
                                    ->sortBy(fn ($s) => array_search($s->name, $order))
                                    ->mapWithKeys(fn ($s) => [$s->id => ucwords(str_replace('_', ' ', $s->name))])
                                    ->toArray();
                            })
                            ->colors(fn (?Order $record): array => [
                                OrderStatus::query()->where('name', 'requested')->value('id') => 'gray',
                                OrderStatus::query()->where('name', 'confirmed')->value('id') => 'info',
                                OrderStatus::query()->where('name', 'preparing')->value('id') => 'warning',
                                OrderStatus::query()->where('name', 'ready_for_pickup')->value('id') => 'success',
                                OrderStatus::query()->where('name', 'completed')->value('id') => 'success',
                                OrderStatus::query()->where('name', 'cancelled')->value('id') => 'danger',
                            ])
                            ->inline()
                            ->disabledOn('create')
                            ->dehydrated()
                            ->hiddenOn('create')
                            ->columnSpanFull(),
                        Toggle::make('is_non_prescription')
                            ->label('Non-Prescription Order')
                            ->default(true)
                            ->disabledOn('edit')
                            ->dehydrated()
                            ->live(),
                        Select::make('prescription_id')
                            ->label('Prescription')
                            ->options(function (Get $get, ?Order $record): array {
                                $customerId = $get('customer_id') ?? $record?->customer_id;
                                if (! $customerId) {
                                    return [];
                                }

                                return Prescription::query()
                                    ->where('customer_id', $customerId)
                                    ->get()
                                    ->mapWithKeys(fn ($p) => [
                                        $p->id => "#{$p->id} — {$p->prescribed_at->format('M j, Y')}",
                                    ])
                                    ->toArray();
                            })
                            ->visible(fn (Get $get): bool => ! $get('is_non_prescription'))
                            ->disabled()
                            ->dehydrated(),
                        RichEditor::make('notes')
                            ->label('Staff Notes')
                            ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList'])
                            ->columnSpanFull(),
                    ])->columns(2),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make()->schema([
                        Placeholder::make('created_at')
                            ->label('Order date')
                            ->content(fn (?Order $record): string => $record?->created_at?->diffForHumans() ?? '—'),
                        Placeholder::make('updated_at')
                            ->label('Last modified at')
                            ->content(fn (?Order $record): string => $record?->updated_at?->diffForHumans() ?? '—'),
                    ])->hiddenOn('create'),
                ]),
            ]),

            // ── Order Items (full width) ─────────────────────────────
            Section::make('Order Items')
                ->headerActions([
                    Action::make('reset_items')
                        ->label('Reset')
                        ->icon('heroicon-o-arrow-path')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (?Order $record): bool => $record?->status?->name === 'requested')
                        ->action(function ($livewire): void {
                            $livewire->dispatch('resetItems');
                        }),
                ])
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            // Row 1: Product(2) | Qty(1) | Unit Price(1)
                            Select::make('product_variant_id')
                                ->label('Product')
                                ->options(fn () => ProductVariant::query()
                                    ->with('product')
                                    ->where('is_active', true)
                                    ->get()
                                    ->mapWithKeys(fn ($v) => [$v->id => "{$v->product->name} — {$v->name}"])
                                    ->toArray()
                                )
                                ->required()
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Set $set, Get $get, ?int $state): void {
                                    if (! $state) {
                                        return;
                                    }

                                    $variant = ProductVariant::with('product')->find($state);
                                    if (! $variant) {
                                        return;
                                    }

                                    $set('product_id', $variant->product_id);
                                    $set('product_name', $variant->product->name);
                                    $set('variant_name', $variant->name);
                                    $set('variant_sku', $variant->sku);
                                    $set('unit_price', $variant->price);

                                    $lensTypeId = $get('lens_type_id');
                                    $lensType = $lensTypeId ? LensType::find($lensTypeId) : null;
                                    $lensPrice = (float) ($lensType?->price ?? 0);
                                    $qty = max(1, (int) $get('quantity'));
                                    $set('subtotal', bcmul(bcadd((string) $variant->price, (string) $lensPrice, 2), (string) $qty, 2));
                                }),
                            TextInput::make('quantity')
                                ->label('Qty')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->live(onBlur: true)
                                ->columnSpan(1)
                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                    $unitPrice = (float) ($get('unit_price') ?? 0);
                                    $lensPrice = (float) ($get('lens_type_price') ?? 0);
                                    $qty = max(1, (int) $state);
                                    $set('subtotal', bcmul(bcadd((string) $unitPrice, (string) $lensPrice, 2), (string) $qty, 2));
                                }),
                            TextInput::make('unit_price')
                                ->label('Unit Price')
                                ->prefix('₱')
                                ->disabled()
                                ->dehydrated()
                                ->columnSpan(1),
                            // Row 2 (lens only): Lens Type(1) | Assigned Lens(2) | Lens Price(1)
                            Select::make('lens_type_id')
                                ->label('Lens Type')
                                ->options(fn () => LensType::query()->pluck('name', 'id'))
                                ->nullable()
                                ->placeholder('No lens')
                                ->live()
                                ->columnSpan(1)
                                ->afterStateUpdated(function (Set $set, Get $get, ?int $state): void {
                                    $lensType = $state ? LensType::find($state) : null;
                                    $set('lens_type_name', $lensType?->name);
                                    $set('lens_type_price', $lensType?->price);
                                    $set('lens_product_variant_id', null);
                                    $unitPrice = (float) ($get('unit_price') ?? 0);
                                    $lensPrice = (float) ($lensType?->price ?? 0);
                                    $qty = max(1, (int) $get('quantity'));
                                    $set('subtotal', bcmul(bcadd((string) $unitPrice, (string) $lensPrice, 2), (string) $qty, 2));
                                }),
                            Select::make('lens_product_variant_id')
                                ->label('Assigned Lens')
                                ->options(function (Get $get): array {
                                    $lensTypeId = $get('lens_type_id');
                                    if (! $lensTypeId) {
                                        return [];
                                    }

                                    return ProductVariant::query()
                                        ->whereHas('product', fn ($q) => $q
                                            ->where('product_type', 'lens')
                                            ->where('lens_type_id', $lensTypeId)
                                            ->where('is_active', true)
                                        )
                                        ->where('is_active', true)
                                        ->with('product')
                                        ->get()
                                        ->mapWithKeys(fn ($v) => [$v->id => "{$v->product->name} — {$v->name}"])
                                        ->toArray();
                                })
                                ->nullable()
                                ->placeholder('Not assigned')
                                ->visible(fn (Get $get): bool => (bool) $get('lens_type_id'))
                                ->columnSpan(2),
                            Placeholder::make('lens_price_display')
                                ->label('Lens Price')
                                ->content(fn (Get $get): string => '₱'.number_format((float) ($get('lens_type_price') ?? 0), 2))
                                ->visible(fn (Get $get): bool => (bool) $get('lens_type_id'))
                                ->columnSpan(1),
                            Hidden::make('subtotal'),
                            Hidden::make('product_id'),
                            Hidden::make('product_name'),
                            Hidden::make('variant_name'),
                            Hidden::make('variant_sku'),
                            Hidden::make('lens_type_name'),
                            Hidden::make('lens_type_price'),
                        ])
                        ->addActionLabel('Add to order items')
                        ->deleteAction(fn (Action $action) => $action->iconButton())
                        ->disabled(fn (?Order $record): bool => $record?->status?->name !== 'requested')
                        ->deletable(fn (?Order $record): bool => $record?->status?->name === 'requested')
                        ->reorderable(fn (?Order $record): bool => $record?->status?->name === 'requested'),
                ]),

            Section::make('Order Summary')
                ->hiddenOn('create')
                ->schema([
                    Grid::make(3)->schema([
                        Placeholder::make('subtotal_display')
                            ->label('Subtotal')
                            ->content(fn (?Order $record): string => $record ? '₱'.number_format((float) $record->subtotal, 2) : '—'),
                        Placeholder::make('discount_display')
                            ->label('Discount')
                            ->content(fn (?Order $record): string => $record && (float) $record->discount_amount > 0
                                ? '-₱'.number_format((float) $record->discount_amount, 2)
                                : '—'),
                        Placeholder::make('total_display')
                            ->label('Total')
                            ->content(fn (?Order $record): string => $record ? '₱'.number_format((float) $record->total_amount, 2) : '—'),
                        Hidden::make('total_amount')->dehydrated(),
                    ]),
                ]),
        ]);
    }
}
