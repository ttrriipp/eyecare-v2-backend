<?php

namespace App\Filament\Support;

use App\Actions\Inventory\RecordInventoryMovement;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Stock movements shared by every surface that adjusts inventory.
 *
 * Both the Inventory resource and the Products > Variants relation manager
 * offer these, so the ledger-writing logic is defined once here rather than
 * duplicated per surface.
 */
class StockActions
{
    public static function receive(): Action
    {
        return Action::make('adjustStock')
            ->label('Receive Stock')
            ->icon('heroicon-o-archive-box')
            ->color('success')
            ->schema([
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->required()
                    ->numeric()
                    ->minValue(1),
                TextInput::make('source_reference')
                    ->label('Reference')
                    ->placeholder('PO number or supplier reference'),
                TextInput::make('notes')
                    ->placeholder('Optional notes'),
            ])
            ->action(function (array $data, ProductVariant $record): void {
                app(RecordInventoryMovement::class)->handle(
                    variant: $record,
                    quantityChange: (int) $data['quantity'],
                    type: 'restock',
                    notes: $data['notes'] ?? null,
                    actingUser: auth()->user(),
                );

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

    public static function writeOffDamaged(): Action
    {
        return Action::make('writeOffDamaged')
            ->label('Write Off Damaged')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Write off damaged stock')
            ->modalDescription('This will permanently reduce the stock count and record the loss in Inventory History.')
            ->schema([
                TextInput::make('quantity')
                    ->label('Units to write off')
                    ->required()
                    ->numeric()
                    ->minValue(1),
                TextInput::make('notes')
                    ->label('Damage reason')
                    ->required()
                    ->placeholder('e.g. Frame scratched during display, lens cracked in storage'),
            ])
            ->action(function (array $data, ProductVariant $record): void {
                app(RecordInventoryMovement::class)->handle(
                    variant: $record,
                    quantityChange: -(int) $data['quantity'],
                    type: 'damaged',
                    notes: $data['notes'],
                    actingUser: auth()->user(),
                );

                Notification::make()
                    ->title('Damaged stock written off')
                    ->body("{$data['quantity']} unit(s) removed from inventory.")
                    ->warning()
                    ->send();
            });
    }
}
