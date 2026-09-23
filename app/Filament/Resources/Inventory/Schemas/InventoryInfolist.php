<?php

namespace App\Filament\Resources\Inventory\Schemas;

use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class InventoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product details')
                    ->schema([
                        TextEntry::make('product.name')
                            ->label('Product')
                            ->weight('bold'),
                        TextEntry::make('product.brand.name')
                            ->label('Brand')
                            ->placeholder('—'),
                        TextEntry::make('name')
                            ->label('Variant'),
                        TextEntry::make('sku')
                            ->label('SKU')
                            ->copyable(),
                        TextEntry::make('product.product_type')
                            ->label('Type')
                            ->formatStateUsing(fn (?string $state): ?string => $state === null
                                ? null
                                : Product::TYPE_OPTIONS[$state] ?? Str::headline($state)),
                        TextEntry::make('product.usage')
                            ->label('Usage')
                            ->state(fn (ProductVariant $record): ?string => $record->product?->usage?->getLabel())
                            ->placeholder('Not set')
                            ->visible(fn (?ProductVariant $record): bool => in_array(
                                $record?->product?->product_type,
                                Product::USAGE_TRACKED_TYPES,
                                true,
                            )),
                        TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Stock details')
                    ->schema([
                        TextEntry::make('latest_purchase_date')
                            ->label('Received At')
                            ->date('M j, Y')
                            ->placeholder('—'),
                        TextEntry::make('early_purchase_quantity')
                            ->label('Early Quantity')
                            ->numeric()
                            ->placeholder('—'),
                        TextEntry::make('stock_quantity')
                            ->label('On Hand')
                            ->numeric(),
                        TextEntry::make('low_stock_threshold')
                            ->label('Threshold')
                            ->numeric()
                            ->placeholder('Not set'),
                        TextEntry::make('target_stock_level')
                            ->label('Target')
                            ->numeric()
                            ->placeholder('Not set'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Expiry details')
                    ->schema([
                        TextEntry::make('usable_stock')
                            ->label('Usable')
                            ->state(fn (ProductVariant $record): ?int => $record->usableStockQuantity())
                            ->numeric()
                            ->badge()
                            ->color(fn (ProductVariant $record): string => match ($record->expiryStatus()) {
                                'out_of_stock', 'expired' => 'danger',
                                'expiring_soon' => 'warning',
                                default => 'success',
                            }),
                        TextEntry::make('earliest_expiry')
                            ->label('Earliest Expiry')
                            ->state(fn (ProductVariant $record): ?string => $record->earliestUsableExpiry()?->toDateString())
                            ->date('M j, Y')
                            ->placeholder('—'),
                        TextEntry::make('expiry_warning_date')
                            ->label('Expiring Soon Date')
                            ->state(fn (ProductVariant $record): ?string => $record->earliestExpiryWarningDate()?->toDateString())
                            ->date('M j, Y')
                            ->placeholder('—'),
                        TextEntry::make('expiry_status')
                            ->label('Expiry Status')
                            ->state(fn (ProductVariant $record): ?string => $record->expiryStatusLabel())
                            ->badge()
                            ->color(fn (ProductVariant $record): string => match ($record->expiryStatus()) {
                                'out_of_stock', 'expired' => 'danger',
                                'expiring_soon' => 'warning',
                                default => 'success',
                            }),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (?ProductVariant $record): bool => $record?->isExpiryTracked() ?? false),
            ]);
    }
}
