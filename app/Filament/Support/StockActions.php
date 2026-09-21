<?php

namespace App\Filament\Support;

use App\Actions\Inventory\ReceiveContactLensStock;
use App\Actions\Inventory\ReceiveFrameStock;
use App\Actions\Inventory\RecordInventoryMovement;
use App\Actions\Inventory\WriteOffContactLensStock;
use App\Actions\Inventory\WriteOffFrameStock;
use App\Models\InventoryLot;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
            ->modalHeading(fn (ProductVariant $record): string => match (true) {
                $record->isContactLens() => 'Contact-lens batches',
                $record->isFrame() => 'Frame batches',
                default => 'Accessory batches',
            })
            ->modalWidth('3xl')
            ->modalContent(function (ProductVariant $record) {
                $lotsQuery = $record->inventoryLots()
                    ->with('receivedBy');

                if ($record->isExpiryTracked()) {
                    $lotsQuery
                        ->orderBy('expires_on')
                        ->orderBy('id');
                } else {
                    $lotsQuery
                        ->orderBy('purchased_at')
                        ->orderBy('received_at')
                        ->orderBy('id');
                }

                $lots = $lotsQuery->get();

                return view('filament.inventory.inventory-lots', [
                    'lots' => $lots,
                    'showExpiry' => $record->isExpiryTracked(),
                    'unbatchedQuantity' => $record->isFrame()
                        ? max(0, (int) $record->stock_quantity - (int) $lots->sum('quantity_on_hand'))
                        : 0,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->visible(fn (ProductVariant $record): bool => $record->isBatchTracked());
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

                if ($record->isExpiryTracked()) {
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
                        purchasedAt: $data['purchased_at'] ?? null,
                    );
                } elseif ($record->isFrame()) {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        throw new AuthenticationException;
                    }

                    app(ReceiveFrameStock::class)->handle(
                        variant: $record,
                        quantity: (int) $data['quantity'],
                        receiver: $actor,
                        batchNumber: $data['batch_number'] ?? null,
                        sourceReference: $data['source_reference'] ?? null,
                        notes: $data['notes'] ?? null,
                        purchasedAt: $data['purchased_at'] ?? null,
                    );
                } else {
                    app(RecordInventoryMovement::class)->handle(
                        variant: $record,
                        quantityChange: (int) $data['quantity'],
                        type: 'restock',
                        notes: $data['notes'] ?? null,
                        actingUser: auth()->user(),
                        purchasedAt: $data['purchased_at'] ?? null,
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

        if ($record->isExpiryTracked()) {
            $fields[] = TextInput::make('lot_number')
                ->label('Lot number')
                ->required()
                ->maxLength(50)
                ->placeholder('Printed on the box');
            $fields[] = TextInput::make('expiry_month')
                ->label('Expiry month')
                ->required()
                ->type('month')
                ->extraInputAttributes(['min' => now()->format('Y-m')])
                ->placeholder('YYYY-MM')
                ->helperText(fn (ProductVariant $record): string => $record->isContactLens()
                    ? 'Use the expiry month printed on the box. It must be the current month or later; expired contact lens stock cannot be received.'
                    : 'Use the expiry month printed on the package. It must be the current month or later; expired accessory stock cannot be received.');
        } elseif ($record->isFrame()) {
            $fields[] = TextInput::make('batch_number')
                ->label('Batch number (optional)')
                ->maxLength(50)
                ->placeholder('Leave blank to generate automatically')
                ->helperText('Leave blank to generate a batch number automatically. Administrators may enter a custom batch number.')
                ->visible(fn (): bool => auth()->user()?->isAdmin() === true);
        }

        $fields[] = TextInput::make('source_reference')
            ->label('Reference')
            ->placeholder('PO number or supplier reference');
        $fields[] = DatePicker::make('purchased_at')
            ->label(fn (ProductVariant $record): string => $record->isFrame()
                ? 'Date Received'
                : 'Date of Purchase')
            ->default(now())
            ->maxDate(now())
            ->required();
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
                $notes = self::resolveDamageReason($data);

                if ($record->isExpiryTracked()) {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        throw new AuthenticationException;
                    }

                    app(WriteOffContactLensStock::class)->handle(
                        variant: $record,
                        quantity: (int) $data['quantity'],
                        inventoryLotId: (int) $data['inventory_lot_id'],
                        actor: $actor,
                        notes: $notes,
                    );
                } elseif ($record->isFrame() && self::hasAvailableFrameBatches($record)) {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        throw new AuthenticationException;
                    }

                    app(WriteOffFrameStock::class)->handle(
                        variant: $record,
                        quantity: (int) $data['quantity'],
                        inventoryLotId: (int) $data['inventory_lot_id'],
                        actor: $actor,
                        notes: $notes,
                    );
                } else {
                    app(RecordInventoryMovement::class)->handle(
                        variant: $record,
                        quantityChange: -(int) $data['quantity'],
                        type: 'damaged',
                        notes: $notes,
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

        if ($record->isExpiryTracked()) {
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
        } elseif ($record->isFrame() && self::hasAvailableFrameBatches($record)) {
            $batchOptions = InventoryLot::query()
                ->where('product_variant_id', $record->id)
                ->whereNull('expires_on')
                ->available()
                ->orderBy('purchased_at')
                ->orderBy('received_at')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(function (InventoryLot $batch): array {
                    $receivedDate = $batch->purchased_at ?? $batch->received_at;

                    return [
                        $batch->id => "{$batch->lot_number} — received {$receivedDate->format('M j, Y')} ({$batch->quantity_on_hand} on hand)",
                    ];
                })
                ->all();

            $fields[] = Select::make('inventory_lot_id')
                ->label('Batch')
                ->options($batchOptions)
                ->searchable()
                ->required()
                ->helperText('Choose the batch containing the damaged frames.');
        }

        $fields[] = Select::make('damage_reason')
            ->label('Damage reason')
            ->options(self::damageReasonOptions())
            ->live()
            ->required(fn (callable $get): bool => blank($get('notes')))
            ->searchable();

        $fields[] = Textarea::make('notes')
            ->label('Details')
            ->required(fn (callable $get): bool => blank($get('damage_reason')) || $get('damage_reason') === 'other')
            ->visible(fn (callable $get): bool => blank($get('damage_reason')) || $get('damage_reason') === 'other')
            ->maxLength(1000)
            ->columnSpanFull();

        return $fields;
    }

    /**
     * Resolve the selected damage preset into the existing movement notes field.
     *
     * The notes key remains the form state key so existing write-off callers
     * that provide a custom reason continue to work.
     *
     * @param  array<string, mixed>  $data
     */
    private static function resolveDamageReason(array $data): string
    {
        $preset = $data['damage_reason'] ?? null;

        if ($preset === 'other') {
            return trim((string) ($data['notes'] ?? ''));
        }

        if (filled($preset)) {
            return self::damageReasonOptions()[$preset] ?? trim((string) $preset);
        }

        return trim((string) ($data['notes'] ?? ''));
    }

    /**
     * @return array<string, string>
     */
    private static function damageReasonOptions(): array
    {
        return [
            'frame_scratched' => 'Frame scratched during display',
            'lens_cracked' => 'Lens cracked in storage',
            'packaging_damaged' => 'Packaging damaged',
            'water_damage' => 'Water or moisture damage',
            'manufacturing_defect' => 'Manufacturing defect',
            'other' => 'Other',
        ];
    }

    private static function hasAvailableFrameBatches(ProductVariant $record): bool
    {
        return InventoryLot::query()
            ->where('product_variant_id', $record->id)
            ->whereNull('expires_on')
            ->available()
            ->exists();
    }
}
