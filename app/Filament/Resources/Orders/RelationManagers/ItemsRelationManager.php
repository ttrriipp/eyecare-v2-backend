<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
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
        return $table
            ->columns([
                TextColumn::make('product_name')->label('Product'),
                TextColumn::make('variant_name')->label('Variant'),
                TextColumn::make('lens_type_name')->label('Lens Type'),
                TextColumn::make('lensProductVariant.name')
                    ->label('Assigned Lens Product')
                    ->placeholder('Not assigned')
                    ->badge()
                    ->color('info'),
                TextColumn::make('unit_price')->label('Frame Price')->money('PHP'),
                TextColumn::make('lens_type_price')->label('Lens Price')->money('PHP')->placeholder('—'),
                TextColumn::make('quantity')->label('Qty'),
                TextColumn::make('subtotal')->label('Subtotal')->money('PHP'),
            ])
            ->recordActions([
                Action::make('assignLens')
                    ->label('Assign Lens')
                    ->icon('heroicon-o-beaker')
                    ->color('warning')
                    ->visible(fn ($record): bool => $record->lens_type_id !== null)
                    ->schema([
                        Select::make('lens_product_variant_id')
                            ->label('Lens Product Variant')
                            ->options(function ($record): array {
                                return ProductVariant::query()
                                    ->whereHas('product', fn ($q) => $q
                                        ->where('product_type', 'lens')
                                        ->where('lens_type_id', $record->lens_type_id)
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
                            $newSubtotal = bcmul(
                                bcadd((string) $record->unit_price, $newLensPrice, 2),
                                (string) $record->quantity,
                                2
                            );
                            $updates['lens_type_price'] = $newLensPrice;
                            $updates['subtotal'] = $newSubtotal;
                        }

                        $record->update($updates);

                        // Recalculate order subtotal and total from item subtotals
                        $order = $record->order()->with('items')->first();
                        $newSubtotalOrder = $order->items->sum(fn ($i): float => (float) $i->fresh()->subtotal);
                        $newTotal = bcsub(
                            number_format($newSubtotalOrder, 2, '.', ''),
                            (string) $order->discount_amount,
                            2
                        );
                        $order->update([
                            'subtotal' => number_format($newSubtotalOrder, 2, '.', ''),
                            'total_amount' => $newTotal,
                        ]);

                        Notification::make()
                            ->title('Lens product assigned')
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated(false);
    }
}
