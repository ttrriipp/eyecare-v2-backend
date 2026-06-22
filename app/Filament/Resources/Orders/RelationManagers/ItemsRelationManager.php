<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\LensType;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Filament\Actions\Action;
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
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        $isRequested = fn (): bool => $this->getOwnerRecord()->status->name === 'requested';

        return $table
            ->reorderable('id')
            ->columns([
                SelectColumn::make('product_variant_id')
                    ->label('Product')
                    ->options(fn () => ProductVariant::query()
                        ->with('product')
                        ->where('is_active', true)
                        ->get()
                        ->mapWithKeys(fn ($v) => [$v->id => "{$v->product->name} — {$v->name}"])
                        ->toArray()
                    )
                    ->disabled(fn (): bool => ! $isRequested())
                    ->afterStateUpdated(function ($state, OrderItem $record): void {
                        if (! $state) {
                            return;
                        }

                        $variant = ProductVariant::find($state);
                        if (! $variant) {
                            return;
                        }

                        $lensPrice = (float) ($record->lens_type_price ?? 0);
                        $unitPrice = (float) $variant->price;
                        $subtotal = bcmul(
                            bcadd((string) $unitPrice, (string) $lensPrice, 2),
                            (string) $record->quantity,
                            2
                        );

                        $record->update([
                            'product_variant_id' => $state,
                            'product_id' => $variant->product_id,
                            'product_name' => $variant->product->name,
                            'variant_name' => $variant->name,
                            'variant_sku' => $variant->sku,
                            'unit_price' => $unitPrice,
                            'subtotal' => $subtotal,
                        ]);

                        $this->recalculateOrderTotal($record);
                    })
                    ->searchable()
                    ->columnSpan(3),
                TextInputColumn::make('quantity')
                    ->label('Qty')
                    ->type('number')
                    ->rules(['min:1'])
                    ->disabled(fn (): bool => ! $isRequested())
                    ->afterStateUpdated(function ($state, OrderItem $record): void {
                        $subtotal = bcmul(
                            bcadd((string) $record->unit_price, (string) ($record->lens_type_price ?? 0), 2),
                            (string) max(1, (int) $state),
                            2
                        );
                        $record->update(['subtotal' => $subtotal]);
                        $this->recalculateOrderTotal($record);
                    }),
                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->money('PHP'),
                TextColumn::make('lens_type_name')
                    ->label('Lens Type')
                    ->placeholder('No lens'),
                TextColumn::make('lensProductVariant.name')
                    ->label('Assigned Lens')
                    ->placeholder('Not assigned')
                    ->badge()
                    ->color(fn ($record): string => $record->lens_type_id && ! $record->lens_product_variant_id ? 'warning' : 'info'),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('PHP'),
            ])
            ->recordActions([
                Action::make('assignLens')
                    ->label('Assign Lens')
                    ->icon('heroicon-o-beaker')
                    ->color('warning')
                    ->visible(fn ($record): bool => $record->lens_type_id !== null
                        && $this->getOwnerRecord()->status->name === 'requested')
                    ->schema([
                        Select::make('lens_product_variant_id')
                            ->label('Lens Product Variant')
                            ->options(function ($record): array {
                                return ProductVariant::query()
                                    ->whereHas('product', fn ($q) => $q
                                        ->where('product_type', 'lens')
                                        ->when($record->lens_type_id, fn ($q, $id) => $q->where('lens_type_id', $id))
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
                    ->fillForm(fn ($record): array => [
                        'lens_product_variant_id' => $record->lens_product_variant_id,
                    ])
                    ->action(function (array $data, $record): void {
                        $lensVariantId = $data['lens_product_variant_id'];
                        $updates = ['lens_product_variant_id' => $lensVariantId];

                        if ($lensVariantId !== null) {
                            $lensVariant = ProductVariant::query()->findOrFail($lensVariantId);
                            $newLensPrice = (string) $lensVariant->price;
                            $updates['lens_type_price'] = $newLensPrice;
                            $updates['subtotal'] = bcmul(
                                bcadd((string) $record->unit_price, $newLensPrice, 2),
                                (string) $record->quantity,
                                2
                            );
                        }

                        $record->update($updates);
                        $this->recalculateOrderTotal($record);

                        Notification::make()->title('Lens product assigned')->success()->send();
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => $isRequested())
                    ->after(fn (OrderItem $record) => $this->recalculateOrderTotal($record)),
            ])
            ->headerActions([
                Action::make('addItem')
                    ->label('Add to order items')
                    ->visible(fn (): bool => $isRequested())
                    ->schema([
                        Select::make('product_variant_id')
                            ->label('Product')
                            ->options(fn () => ProductVariant::query()
                                ->with('product')
                                ->where('is_active', true)
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
                        Select::make('lens_type_id')
                            ->label('Lens Type')
                            ->options(fn () => LensType::query()->pluck('name', 'id'))
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
                    ])
                    ->action(function (array $data): void {
                        $variant = ProductVariant::with('product')->findOrFail($data['product_variant_id']);
                        $lensType = $data['lens_type_id'] ? LensType::find($data['lens_type_id']) : null;
                        $unitPrice = (float) $variant->price;
                        $lensPrice = (float) ($lensType?->price ?? 0);
                        $subtotal = bcmul(bcadd((string) $unitPrice, (string) $lensPrice, 2), (string) $data['quantity'], 2);

                        $item = $this->getOwnerRecord()->items()->create([
                            'product_variant_id' => $variant->id,
                            'product_id' => $variant->product_id,
                            'product_name' => $variant->product->name,
                            'variant_name' => $variant->name,
                            'variant_sku' => $variant->sku,
                            'lens_type_id' => $lensType?->id,
                            'lens_type_name' => $lensType?->name,
                            'lens_type_price' => $lensPrice > 0 ? $lensPrice : null,
                            'unit_price' => $unitPrice,
                            'quantity' => $data['quantity'],
                            'subtotal' => $subtotal,
                        ]);

                        $this->recalculateOrderTotal($item);
                        Notification::make()->title('Item added')->success()->send();
                    }),
            ])
            ->paginated(false);
    }

    private function recalculateOrderTotal(OrderItem $item): void
    {
        $order = $this->getOwnerRecord();
        $order->loadMissing('items');
        $newSubtotal = $order->items->sum(fn ($i): float => (float) $i->fresh()->subtotal);
        $newTotal = bcsub(number_format($newSubtotal, 2, '.', ''), (string) $order->discount_amount, 2);
        $order->update([
            'subtotal' => number_format($newSubtotal, 2, '.', ''),
            'total_amount' => $newTotal,
        ]);
    }
}
