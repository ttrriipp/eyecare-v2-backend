<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Actions\Inventory\RecordInventoryMovement;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Get as FormGet;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
            TextInput::make('target_stock_level')
                ->label('Target Stock Level')
                ->helperText('Optional. When set, restock suggestions replenish inventory to this level.')
                ->nullable()
                ->integer()
                ->minValue(0)
                ->gte('low_stock_threshold')
                ->default(null),
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
                ->label('AR Asset (PNG or 3D Model)')
                ->disk('public')
                ->directory('ar-assets')
                ->visibility('public')
                ->maxSize(10240)
                ->helperText('Accepted: .png, .glb, .gltf, .obj')
                ->rules(['nullable', 'file', 'extensions:png,glb,gltf,obj'])
                ->helperText('PNG overlay or 3D model (.glb, .gltf, .obj)')
                ->required(fn (Get $get): bool => (bool) $get('ar_eligible'))
                ->visible(fn (Get $get, RelationManager $livewire): bool => $livewire->getOwnerRecord()->product_type === 'frame' && (bool) $get('ar_eligible')),

            // Frame specific fields
            Section::make('Frame Dimensions')
                ->schema([
                    TextInput::make('attributes.bridge')
                        ->label('Bridge (mm)')
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(30),
                    TextInput::make('attributes.temple')
                        ->label('Temple (mm)')
                        ->numeric()
                        ->minValue(100)
                        ->maxValue(160),
                    TextInput::make('attributes.lens_width')
                        ->label('Lens Width (mm)')
                        ->numeric()
                        ->minValue(30)
                        ->maxValue(70),
                    TextInput::make('attributes.lens_height')
                        ->label('Lens Height (mm)')
                        ->numeric()
                        ->minValue(20)
                        ->maxValue(60),
                    TextInput::make('attributes.color')
                        ->label('Color')
                        ->maxLength(50),
                    TextInput::make('attributes.material')
                        ->label('Material')
                        ->maxLength(50),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()->product_type === 'frame'),

            // Generic attributes for other product types
            KeyValue::make('attributes')
                ->label('Attributes')
                ->columnSpanFull()
                ->visible(fn (RelationManager $livewire): bool => ! in_array($livewire->getOwnerRecord()->product_type, ['contact_lens', 'frame'])),

            // Contact-lens specific fields
            Section::make('Contact Lens Parameters')
                ->schema([
                    TextInput::make('attributes.power')
                        ->label('Power')
                        ->placeholder('-2.00')
                        ->required()
                        ->maxLength(20),
                    TextInput::make('attributes.base_curve')
                        ->label('Base Curve')
                        ->placeholder('8.6')
                        ->required()
                        ->numeric()
                        ->minValue(7)
                        ->maxValue(12),
                    TextInput::make('attributes.diameter')
                        ->label('Diameter (mm)')
                        ->placeholder('14.0')
                        ->required()
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(20),
                    TextInput::make('attributes.cylinder')
                        ->label('Cylinder')
                        ->placeholder('-1.25')
                        ->maxLength(20),
                    TextInput::make('attributes.axis')
                        ->label('Axis')
                        ->placeholder('180')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(180),
                    TextInput::make('attributes.add')
                        ->label('Add')
                        ->placeholder('+2.00')
                        ->maxLength(20),
                    TextInput::make('attributes.color')
                        ->label('Color')
                        ->placeholder('Blue')
                        ->maxLength(50),
                    TextInput::make('attributes.pack_size')
                        ->label('Pack Size')
                        ->placeholder('30')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(999),
                ])
                ->columns(4)
                ->columnSpanFull()
                ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()->product_type === 'contact_lens'),

            // Lot information for contact-lens variants
            Section::make('Lots')
                ->schema([
                    Repeater::make('lots')
                        ->relationship()
                        ->schema([
                            TextInput::make('lot_number')
                                ->label('Lot #')
                                ->disabled(),
                            DatePicker::make('expires_on')
                                ->label('Expires')
                                ->disabled(),
                            TextInput::make('quantity_on_hand')
                                ->label('Qty')
                                ->disabled(),
                            Placeholder::make('status')
                                ->label('Status')
                                ->content(fn ($record): string => match (true) {
                                    $record === null => '—',
                                    $record->isExpired() => 'Expired',
                                    $record->expires_on->diffInDays(now()) <= 30 => 'Near Expiry',
                                    default => 'OK',
                                }),
                        ])
                        ->columns(4)
                        ->disableItemCreation()
                        ->disableItemDeletion()
                        ->disableItemMovement()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['lot_number'] ?? null),
                ])
                ->columnSpanFull()
                ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()->product_type === 'contact_lens'),

            FileUpload::make('images')
                ->disk('public')
                ->directory('variants')
                ->visibility('public')
                ->image()
                ->imageEditor()
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
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([SoftDeletingScope::class]))
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
                TextColumn::make('contact_lens_params')
                    ->label('Parameters')
                    ->state(function ($record): ?string {
                        if ($record->product?->product_type !== 'contact_lens') {
                            return null;
                        }

                        $attrs = $record->attributes ?? [];
                        $parts = [];

                        if (filled($attrs['power'] ?? null)) {
                            $parts[] = "PWR: {$attrs['power']}";
                        }
                        if (filled($attrs['base_curve'] ?? null)) {
                            $parts[] = "BC: {$attrs['base_curve']}";
                        }
                        if (filled($attrs['diameter'] ?? null)) {
                            $parts[] = "DIA: {$attrs['diameter']}";
                        }
                        if (filled($attrs['cylinder'] ?? null)) {
                            $parts[] = "CYL: {$attrs['cylinder']}";
                        }
                        if (filled($attrs['axis'] ?? null)) {
                            $parts[] = "AX: {$attrs['axis']}";
                        }
                        if (filled($attrs['add'] ?? null)) {
                            $parts[] = "ADD: {$attrs['add']}";
                        }
                        if (filled($attrs['color'] ?? null)) {
                            $parts[] = $attrs['color'];
                        }
                        if (filled($attrs['pack_size'] ?? null)) {
                            $parts[] = "{$attrs['pack_size']}pk";
                        }

                        return filled($parts) ? implode(' / ', $parts) : '—';
                    })
                    ->wrap()
                    ->limit(100)
                    ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'contact_lens'),
                TextColumn::make('frame_dimensions')
                    ->label('Dimensions')
                    ->state(function ($record): ?string {
                        if ($record->product?->product_type !== 'frame') {
                            return null;
                        }

                        $attrs = $record->attributes ?? [];
                        $parts = [];

                        if (filled($attrs['lens_width'] ?? null)) {
                            $parts[] = "{$attrs['lens_width']}mm";
                        }
                        if (filled($attrs['bridge'] ?? null)) {
                            $parts[] = "{$attrs['bridge']} bridge";
                        }
                        if (filled($attrs['temple'] ?? null)) {
                            $parts[] = "{$attrs['temple']} temple";
                        }
                        if (filled($attrs['color'] ?? null)) {
                            $parts[] = $attrs['color'];
                        }
                        if (filled($attrs['material'] ?? null)) {
                            $parts[] = $attrs['material'];
                        }

                        return filled($parts) ? implode(' / ', $parts) : '—';
                    })
                    ->wrap()
                    ->limit(100)
                    ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'frame'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->filters([
                TrashedFilter::make()
                    ->label('Show Archived')
                    ->placeholder('Active only')
                    ->trueLabel('Active and archived')
                    ->falseLabel('Archived only'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->color('info'),
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

                            $updatedStock = $record->fresh()->stock_quantity;

                            if ($isAddition && $record->target_stock_level !== null && $updatedStock > $record->target_stock_level) {
                                Notification::make()
                                    ->title('Stock exceeds target')
                                    ->body("Stock was updated to {$updatedStock}; the configured target is {$record->target_stock_level}.")
                                    ->warning()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Stock updated')
                                    ->success()
                                    ->send();
                            }
                        }),
                    Action::make('writeOffDamaged')
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
                        ->action(function (array $data, $record): void {
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
                        }),
                    RestoreAction::make()
                        ->label('Restore')
                        ->visible(fn ($record): bool => (auth()->user()?->isAdmin() ?? false) && $record->trashed()),
                    DeleteAction::make()
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->modalIcon('heroicon-o-archive-box')
                        ->modalHeading('Archive variant')
                        ->modalDescription('This will hide the variant from active lists. It can be restored later from the "Show Archived" filter.')
                        ->modalSubmitActionLabel('Archive')
                        ->color('danger')
                        ->visible(fn ($record): bool => (auth()->user()?->isAdmin() ?? false) && ! $record->trashed()),
                ]),
            ]);
    }
}
