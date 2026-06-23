<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Actions\Inventory\RecordInventoryMovement;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Get as FormGet;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
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
                ->prefix('₱'),
            TextInput::make('cost_price')
                ->label('Cost Price')
                ->numeric()
                ->prefix('₱')
                ->helperText('Internal only — not shown to customers.'),
            TextInput::make('stock_quantity')
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->disabled()
                ->dehydrated(false),
            TextInput::make('low_stock_threshold')
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(0),
            Toggle::make('is_active')
                ->label('Visibility')
                ->helperText(
                    fn (bool $state): string => $state
                        ? 'This variant is available to customers.'
                        : 'This variant will be hidden from all sales channels.'
                )
                ->default(true),
            Toggle::make('ar_eligible')
                ->live()
                ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'frame'),
            FileUpload::make('ar_asset_reference')
                ->label('AR Overlay Image (PNG)')
                ->disk('public')
                ->directory('ar-assets')
                ->visibility('public')
                ->acceptedFileTypes(['image/png'])
                ->maxSize(10240)
                ->helperText('PNG with transparent background only. Full front-facing frame (both lenses + bridge + temples), landscape ~3:1 ratio (e.g. 900×300px), tight crop, no padding, no background color.')
                ->visible(fn (Get $get): bool => $this->getOwnerRecord()->product_type === 'frame' && (bool) $get('ar_eligible')),
            KeyValue::make('attributes')
                ->columnSpanFull(),
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
                ImageColumn::make('images')
                    ->label('Image')
                    ->state(fn ($record): ?string => collect($record->images)->first())
                    ->disk('public')
                    ->square()
                    ->size(40),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('sku')
                    ->searchable(),
                TextColumn::make('price')
                    ->money('PHP')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Visible')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                IconColumn::make('ar_eligible')
                    ->label('AR')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'frame'),
                TextColumn::make('stock_quantity')
                    ->label('Qty')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->color('info')
                        ->extraModalActions([
                            DeleteAction::make()
                                ->color('danger')
                                ->requiresConfirmation(),
                        ]),
                    Action::make('toggleVisibility')
                        ->label(fn ($record): string => $record->is_active ? 'Hide' : 'Show')
                        ->icon(fn ($record): string => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                        ->color(fn ($record): string => $record->is_active ? 'warning' : 'success')
                        ->action(fn ($record) => $record->update(['is_active' => ! $record->is_active]))
                        ->successNotificationTitle(fn ($record): string => $record->is_active ? 'Variant hidden' : 'Variant visible'),
                    Action::make('adjustPrice')
                        ->label('Adjust Price')
                        ->icon('heroicon-o-currency-dollar')
                        ->color('warning')
                        ->schema([
                            TextInput::make('price')
                                ->label('Selling Price')
                                ->required()
                                ->numeric()
                                ->prefix('₱'),
                            TextInput::make('compare_at_price')
                                ->label('Compare at Price')
                                ->numeric()
                                ->prefix('₱')
                                ->helperText('Original price shown crossed out (sale indicator).'),
                            TextInput::make('cost_price')
                                ->label('Cost Price')
                                ->numeric()
                                ->prefix('₱')
                                ->helperText('Internal only — not shown to customers.'),
                        ])
                        ->fillForm(fn ($record): array => [
                            'price' => $record->price,
                            'compare_at_price' => $record->compare_at_price,
                            'cost_price' => $record->cost_price,
                        ])
                        ->action(fn (array $data, $record) => $record->update([
                            'price' => $data['price'],
                            'compare_at_price' => $data['compare_at_price'],
                            'cost_price' => $data['cost_price'],
                        ]))
                        ->successNotificationTitle('Prices updated'),
                    Action::make('adjustStock')
                        ->label('Adjust Stock')
                        ->icon('heroicon-o-archive-box')
                        ->color('success')
                        ->schema([
                            Select::make('type')
                                ->label('Movement Type')
                                ->options([
                                    'restock' => 'Restock (add units)',
                                    'manual_adjustment' => 'Manual Adjustment (remove units)',
                                ])
                                ->required()
                                ->live(),
                            TextInput::make('quantity')
                                ->label(
                                    fn (FormGet $get): string => $get('type') === 'restock'
                                        ? 'Units to add'
                                        : 'Units to remove'
                                )
                                ->required()
                                ->numeric()
                                ->minValue(1),
                            TextInput::make('notes')
                                ->placeholder('Optional notes'),
                        ])
                        ->action(function (array $data, $record): void {
                            $isAddition = $data['type'] === 'restock';
                            $quantityChange = $isAddition ? (int) $data['quantity'] : -(int) $data['quantity'];

                            app(RecordInventoryMovement::class)->handle(
                                variant: $record,
                                quantityChange: $quantityChange,
                                type: $data['type'],
                                notes: $data['notes'] ?? null,
                                actingUser: auth()->user(),
                            );

                            Notification::make()
                                ->title('Stock updated')
                                ->success()
                                ->send();
                        }),
                    DeleteAction::make()
                        ->color('danger'),
                ]),
            ]);
    }
}
