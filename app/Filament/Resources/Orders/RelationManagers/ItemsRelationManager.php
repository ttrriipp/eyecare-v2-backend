<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\LensCategory;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Order Items';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_variant_id')
                ->label('Product')
                ->options(fn () => ProductVariant::query()
                    ->with('product')
                    ->where('is_active', true)
                    ->whereHas('product', fn ($q) => $q->whereIn('product_type', ['frame', 'general']))
                    ->get()
                    ->mapWithKeys(fn ($v) => [$v->id => "{$v->product->name} — {$v->name} (₱{$v->price})"])
                    ->toArray()
                )
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    if ($state) {
                        $variant = ProductVariant::find($state);
                        $set('unit_price', $variant?->price);
                    }
                }),
            Select::make('lens_category_id')
                ->label('Lens Category')
                ->options(fn () => LensCategory::query()->pluck('name', 'id'))
                ->nullable()
                ->placeholder('No lens required'),
            TextInput::make('quantity')
                ->required()
                ->numeric()
                ->minValue(1)
                ->default(1),
            TextInput::make('unit_price')
                ->label('Unit Price')
                ->prefix('₱')
                ->disabled()
                ->dehydrated(false),
        ]);
    }

    public function table(Table $table): Table
    {
        $isConfirmed = fn (): bool => $this->getOwnerRecord()->status->name === 'confirmed';

        return $table
            ->reorderable('id')
            ->columns([
                SelectColumn::make('product_variant_id')
                    ->label('Product')
                    ->options(fn () => ProductVariant::query()
                        ->with('product')
                        ->where('is_active', true)
                        ->whereHas('product', fn ($q) => $q->whereIn('product_type', ['frame', 'general']))
                        ->get()
                        ->mapWithKeys(fn ($v) => [$v->id => "{$v->product->name} — {$v->name}"])
                        ->toArray()
                    )
                    ->disabled(fn (): bool => ! $isConfirmed())
                    ->afterStateUpdated(function ($state, OrderItem $record): void {
                        if (! $state) {
                            return;
                        }

                        $variant = ProductVariant::with('product')->find($state);
                        if (! $variant) {
                            return;
                        }

                        $lensPrice = (float) ($record->lens_type_price ?? 0);
                        $unitPrice = (float) $variant->price;
                        $subtotal = bcmul(bcadd((string) $unitPrice, (string) $lensPrice, 2), (string) $record->quantity, 2);

                        $record->update([
                            'product_id' => $variant->product_id,
                            'product_name' => $variant->product->name,
                            'variant_name' => $variant->name,
                            'variant_sku' => $variant->sku,
                            'unit_price' => $unitPrice,
                            'subtotal' => $subtotal,
                        ]);

                        $this->recalculateOrderTotal($record);
                    })
                    ->searchable(),
                TextInputColumn::make('quantity')
                    ->label('Qty')
                    ->type('number')
                    ->rules(['min:1'])
                    ->disabled(fn (): bool => ! $isConfirmed())
                    ->afterStateUpdated(function ($state, OrderItem $record): void {
                        $subtotal = bcmul(
                            bcadd((string) $record->unit_price, (string) ($record->lens_type_price ?? 0), 2),
                            (string) max(1, (int) $state),
                            2
                        );
                        $record->update(['subtotal' => $subtotal]);
                        $this->recalculateOrderTotal($record);
                    }),
                TextColumn::make('unit_price')->label('Unit Price')->money('PHP'),
                TextColumn::make('lens_category_name')->label('Lens Category')->placeholder('No lens'),
                TextColumn::make('lensProductVariant.name')
                    ->label('Assigned Lens')
                    ->placeholder('Not assigned')
                    ->badge()
                    ->color(fn ($record): string => $record->lens_category_id && ! $record->lens_product_variant_id ? 'warning' : 'info'),
                TextColumn::make('subtotal')->label('Subtotal')->money('PHP'),
            ])
            ->headerActions([
                Action::make('reset')
                    ->label('Reset')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $isConfirmed())
                    ->action(fn () => $this->dispatch('$refresh')),
            ])
            ->recordActions([
                Action::make('assignLens')
                    ->label('Assign Lens')
                    ->icon('heroicon-o-beaker')
                    ->color('warning')
                    ->iconButton()
                    ->visible(fn ($record): bool => $record->lens_category_id !== null && $isConfirmed())
                    ->schema([
                        Select::make('lens_product_variant_id')
                            ->label('Lens Product Variant')
                            ->options(function ($record): array {
                                return ProductVariant::query()
                                    ->whereHas('product', fn ($q) => $q
                                        ->where('product_type', 'lens')
                                        ->when($record->lens_category_id, fn ($q, $id) => $q->where('lens_category_id', $id))
                                        ->where('is_active', true)
                                    )
                                    ->where('is_active', true)
                                    ->with('product')
                                    ->get()
                                    ->mapWithKeys(fn ($v) => [
                                        $v->id => "{$v->product->name} — {$v->name} (Stock: {$v->stock_quantity})",
                                    ])
                                    ->toArray();
                            })
                            ->searchable()
                            ->nullable()
                            ->placeholder('Clear assignment'),
                    ])
                    ->fillForm(fn ($record): array => ['lens_product_variant_id' => $record->lens_product_variant_id])
                    ->action(function (array $data, $record): void {
                        $lensVariantId = $data['lens_product_variant_id'];
                        $updates = ['lens_product_variant_id' => $lensVariantId];

                        if ($lensVariantId !== null) {
                            $lensVariant = ProductVariant::findOrFail($lensVariantId);
                            $newLensPrice = (string) $lensVariant->price;
                            $updates['lens_type_price'] = $newLensPrice;
                            $updates['subtotal'] = bcmul(bcadd((string) $record->unit_price, $newLensPrice, 2), (string) $record->quantity, 2);
                        }

                        $record->update($updates);
                        $this->recalculateOrderTotal($record);

                        Notification::make()->title('Lens product assigned')->success()->send();
                    }),
                DeleteAction::make()
                    ->iconButton()
                    ->visible(fn (): bool => $isConfirmed())
                    ->after(fn (OrderItem $record) => $this->recalculateOrderTotal($record)),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add to order items')
                    ->visible(fn (): bool => $isConfirmed())
                    ->mutateFormDataUsing(function (array $data): array {
                        $variant = ProductVariant::with('product')->findOrFail($data['product_variant_id']);
                        $lensCategory = $data['lens_category_id'] ? LensCategory::find($data['lens_category_id']) : null;
                        $unitPrice = (float) $variant->price;
                        $lensPrice = (float) ($lensCategory?->price ?? 0);

                        return [
                            'product_variant_id' => $variant->id,
                            'product_id' => $variant->product_id,
                            'product_name' => $variant->product->name,
                            'variant_name' => $variant->name,
                            'variant_sku' => $variant->sku,
                            'lens_category_id' => $lensCategory?->id,
                            'lens_category_name' => $lensCategory?->name,
                            'lens_type_price' => $lensPrice > 0 ? $lensPrice : null,
                            'unit_price' => $unitPrice,
                            'quantity' => $data['quantity'],
                            'subtotal' => bcmul(bcadd((string) $unitPrice, (string) $lensPrice, 2), (string) $data['quantity'], 2),
                        ];
                    })
                    ->after(function (): void {
                        $order = $this->getOwnerRecord();
                        $order->loadMissing('items');
                        $newSubtotal = $order->items->sum(fn ($i): float => (float) $i->subtotal);
                        $order->update(['subtotal' => number_format($newSubtotal, 2, '.', ''), 'total_amount' => number_format($newSubtotal, 2, '.', '')]);
                    }),
            ])
            ->paginated(false);
    }

    private function recalculateOrderTotal(OrderItem $item): void
    {
        $order = $this->getOwnerRecord();
        $order->loadMissing('items');
        $newSubtotal = $order->items->sum(fn ($i): float => (float) $i->fresh()->subtotal);
        $order->update(['subtotal' => number_format($newSubtotal, 2, '.', ''), 'total_amount' => number_format($newSubtotal, 2, '.', '')]);
    }
}
