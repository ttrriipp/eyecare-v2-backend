<?php

namespace App\Filament\Support;

use App\Actions\Inventory\ReceiveContactLensStock;
use App\Actions\Inventory\RecordInventoryMovement;
use App\Actions\Inventory\WriteOffContactLensStock;
use App\Models\InventoryLot;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\AuthenticationException;

/**
 * Stock movements shared by every surface that adjusts inventory.
 *
 * Both the Inventory resource and the Products > Variants relation manager
 * offer these, so the ledger-writing logic is defined once here rather than
 * duplicated per surface.
 */
class StockActions
{
    public static function viewBatches(): Action
    {
        return Action::make('viewBatches')
            ->label('View Batches')
            ->icon('heroicon-o-rectangle-stack')
            ->color('gray')
            ->modalHeading('Contact-lens batches')
            ->modalWidth('3xl')
            ->modalContent(function (ProductVariant $record) {
                $lots = $record->inventoryLots()
                    ->with('receivedBy')
                    ->orderBy('expires_on')
                    ->orderBy('id')
                    ->get();

                return view('filament.inventory.inventory-lots', [
                    'lots' => $lots,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->visible(fn (ProductVariant $record): bool => $record->isContactLens());
    }

    public static function receive(): Action
    {
        return Action::make('adjustStock')
            ->label('Receive Stock')
            ->icon('heroicon-o-archive-box')
            ->color('success')
            ->schema(fn (ProductVariant $record): array => self::receiveSchema($record))
            ->action(function (array $data, ProductVariant $record): void {
                $record->load('product');

                if ($record->product?->product_type === 'contact_lens') {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        throw new AuthenticationException;
                    }

                    app(ReceiveContactLensStock::class)->handle(
                        variant: $record,
                        quantity: (int) $data['quantity'],
                        lotNumber: (string) $data['lot_number'],
                        expiryMonth: (string) $data['expiry_month'],
                        receiver: $actor,
                        sourceReference: $data['source_reference'] ?? null,
                        notes: $data['notes'] ?? null,
                    );
                } else {
                    app(RecordInventoryMovement::class)->handle(
                        variant: $record,
                        quantityChange: (int) $data['quantity'],
                        type: 'restock',
                        notes: $data['notes'] ?? null,
                        actingUser: auth()->user(),
                    );
                }

                $updatedStock = $record->fresh()->stock_quantity;

                if ($record->target_stock_level !== null && $updatedStock > $record->target_stock_level) {
                    Notification::make()
                        ->title('Stock exceeds target')
                        ->body("Stock was updated to {$updatedStock}; the configured target is {$record->target_stock_level}.")
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Stock received')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return list<Field>
     */
    private static function receiveSchema(ProductVariant $record): array
    {
        $record->load('product');

        $fields = [
            TextInput::make('quantity')
                ->label('Quantity')
                ->required()
                ->numeric()
                ->minValue(1),
        ];

        if ($record->product?->product_type === 'contact_lens') {
            $fields[] = TextInput::make('lot_number')
                ->label('Lot number')
                ->required()
                ->maxLength(50)
                ->placeholder('Printed on the box');
            $fields[] = TextInput::make('expiry_month')
                ->label('Expiry month')
                ->required()
                ->type('month')
                ->placeholder('YYYY-MM');
        }

        $fields[] = TextInput::make('source_reference')
            ->label('Reference')
            ->placeholder('PO number or supplier reference');
        $fields[] = TextInput::make('notes')
            ->placeholder('Optional notes');

        return $fields;
    }

    public static function writeOffDamaged(): Action
    {
        return Action::make('writeOffDamaged')
            ->label('Write Off Damaged')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Write off damaged stock')
            ->modalDescription('This will permanently reduce the stock count and record the loss in Inventory History.')
            ->schema(fn (ProductVariant $record): array => self::writeOffSchema($record))
            ->action(function (array $data, ProductVariant $record): void {
                $record->load('product');

                if ($record->product?->product_type === 'contact_lens') {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        throw new AuthenticationException;
                    }

                    app(WriteOffContactLensStock::class)->handle(
                        variant: $record,
                        quantity: (int) $data['quantity'],
                        inventoryLotId: (int) $data['inventory_lot_id'],
                        actor: $actor,
                        notes: (string) $data['notes'],
                    );
                } else {
                    app(RecordInventoryMovement::class)->handle(
                        variant: $record,
                        quantityChange: -(int) $data['quantity'],
                        type: 'damaged',
                        notes: $data['notes'],
                        actingUser: auth()->user(),
                    );
                }

                Notification::make()
                    ->title('Damaged stock written off')
                    ->body("{$data['quantity']} unit(s) removed from inventory.")
                    ->warning()
                    ->send();
            });
    }

    /**
     * @return list<Field>
     */
    private static function writeOffSchema(ProductVariant $record): array
    {
        $record->load('product');

        $fields = [
            TextInput::make('quantity')
                ->label('Units to write off')
                ->required()
                ->numeric()
                ->minValue(1),
        ];

        if ($record->product?->product_type === 'contact_lens') {
            $lotOptions = InventoryLot::query()
                ->where('product_variant_id', $record->id)
                ->available()
                ->orderBy('expires_on')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (InventoryLot $lot): array => [
                    $lot->id => "{$lot->lot_number} — expires {$lot->expires_on->format('M Y')} ({$lot->quantity_on_hand} on hand)",
                ])
                ->all();

            $fields[] = Select::make('inventory_lot_id')
                ->label('Lot')
                ->options($lotOptions)
                ->searchable()
                ->required()
                ->helperText('Choose the lot containing the damaged units.');
        }

        $fields[] = TextInput::make('notes')
            ->label('Damage reason')
            ->required()
            ->placeholder('e.g. Frame scratched during display, lens cracked in storage');

        return $fields;
    }
}
