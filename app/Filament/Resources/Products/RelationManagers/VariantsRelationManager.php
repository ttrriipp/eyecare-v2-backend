<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Actions\Inventory\RecordInventoryMovement;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('sku')
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('Auto-generated if blank'),
            TextInput::make('price')
                ->required()
                ->numeric()
                ->prefix('₱'),
            TextInput::make('compare_at_price')
                ->label('Compare at Price')
                ->numeric()
                ->prefix('₱')
                ->helperText('Original price shown crossed out to indicate a sale. Leave blank if not on sale.'),
            TextInput::make('cost_price')
                ->label('Cost Price')
                ->numeric()
                ->prefix('₱')
                ->helperText('Internal only — not shown to customers.'),
            TextInput::make('stock_quantity')
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(0),
            TextInput::make('low_stock_threshold')
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(0),
            Toggle::make('is_active')
                ->label('Visibility')
                ->helperText(fn (bool $state): string => $state
                    ? 'This variant is available to customers.'
                    : 'This variant will be hidden from all sales channels.'
                )
                ->default(true),
            Toggle::make('ar_eligible')
                ->live()
                ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'frame'),
            TextInput::make('ar_asset_reference')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => $this->getOwnerRecord()->product_type === 'frame' && (bool) $get('ar_eligible')),
            KeyValue::make('attributes')
                ->columnSpanFull()
                ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'frame'),
            FileUpload::make('images')
                ->disk('public')
                ->directory('variants')
                ->visibility('public')
                ->image()
                ->multiple()
                ->reorderable()
                ->appendFiles()
                ->maxSize(5120)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('sku')
                    ->searchable(),
                TextColumn::make('price')
                    ->money('PHP')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Visibility')
                    ->boolean()
                    ->trueIcon('heroicon-o-eye')
                    ->falseIcon('heroicon-o-eye-slash')
                    ->trueColor('success')
                    ->falseColor('gray'),
                TextColumn::make('stock_quantity')
                    ->label('Qty')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->color('gray'),
                    EditAction::make()
                        ->color('info'),
                    Action::make('restock')
                        ->label('Restock')
                        ->icon('heroicon-o-plus-circle')
                        ->color('success')
                        ->schema([
                            TextInput::make('quantity')
                                ->label('Units to add')
                                ->required()
                                ->numeric()
                                ->minValue(1),
                            TextInput::make('notes')
                                ->label('Notes')
                                ->placeholder('e.g. Stock received from supplier'),
                        ])
                        ->action(function (array $data, $record): void {
                            app(RecordInventoryMovement::class)->handle(
                                variant: $record,
                                quantityChange: (int) $data['quantity'],
                                type: 'Restock',
                                notes: $data['notes'] ?? null,
                            );
                        })
                        ->successNotificationTitle('Stock updated'),
                    Action::make('adjustPrice')
                        ->label('Adjust Price')
                        ->icon('heroicon-o-currency-dollar')
                        ->color('warning')
                        ->schema([
                            TextInput::make('price')
                                ->required()
                                ->numeric()
                                ->prefix('₱'),
                        ])
                        ->fillForm(fn ($record): array => ['price' => $record->price])
                        ->action(fn (array $data, $record) => $record->update(['price' => $data['price']]))
                        ->successNotificationTitle('Price updated'),
                    Action::make('adjustStock')
                        ->label('Adjust Stock')
                        ->icon('heroicon-o-archive-box')
                        ->color('success')
                        ->schema([
                            TextInput::make('stock_quantity')
                                ->label('Stock Quantity')
                                ->required()
                                ->numeric()
                                ->minValue(0),
                        ])
                        ->fillForm(fn ($record): array => ['stock_quantity' => $record->stock_quantity])
                        ->action(fn (array $data, $record) => $record->update(['stock_quantity' => $data['stock_quantity']]))
                        ->successNotificationTitle('Stock updated'),
                ]),
            ]);
    }
}
