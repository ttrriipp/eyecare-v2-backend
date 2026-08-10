<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('images')
                    ->label('Image')
                    ->state(fn (Product $record): ?string => collect($record->images)->first())
                    ->disk('public')
                    ->square()
                    ->size(48),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('product_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Product::TYPE_OPTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'frame' => 'info',
                        'contact_lens' => 'warning',
                        'accessory' => 'gray',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label('Visible')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('total_quantity')
                    ->label('Qty')
                    ->state(fn (Product $record): int => $record->variants->sum('stock_quantity'))
                    ->sortable(false)
                    ->toggleable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('toggleVisibility')
                        ->label(fn ($record): string => $record->is_active ? 'Hide' : 'Show')
                        ->icon(fn ($record): string => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                        ->color(fn ($record): string => $record->is_active ? 'warning' : 'success')
                        ->action(fn ($record) => $record->update(['is_active' => ! $record->is_active]))
                        ->successNotificationTitle(fn ($record): string => $record->is_active ? 'Product hidden' : 'Product visible'),
                    DeleteAction::make()->label('Archive')->icon('heroicon-o-archive-box')->modalIcon('heroicon-o-archive-box')->modalHeading('Archive product')->modalDescription('This will hide the product from active lists. It can be restored later from the "Show Archived" filter.')->modalSubmitActionLabel('Archive')->color('danger')->visible(fn (Product $record): bool => (auth()->user()?->isAdmin() ?? false) && ! $record->trashed()),
                    RestoreAction::make()->label('Restore')->visible(fn (Product $record): bool => (auth()->user()?->isAdmin() ?? false) && $record->trashed()),
                ]),
            ])
            ->filters([
                SelectFilter::make('product_type')
                    ->label('Type')
                    ->options(Product::TYPE_OPTIONS),
                SelectFilter::make('is_active')
                    ->label('Visibility')
                    ->options([
                        '1' => 'Visible',
                        '0' => 'Hidden',
                    ]),
                TrashedFilter::make()
                    ->label('Show Archived')
                    ->placeholder('Active only')
                    ->trueLabel('Active and archived')
                    ->falseLabel('Archived only'),
            ])
            ->defaultSort('name')
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('toggle_visibility')
                        ->label('Toggle Visibility')
                        ->icon('heroicon-o-eye')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(
                            fn ($record) => $record->update(['is_active' => ! $record->is_active])
                        ))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()->label('Archive Selected')->icon('heroicon-o-archive-box')->modalIcon('heroicon-o-archive-box')->modalHeading('Archive selected products')->modalDescription('This will hide the selected products from active lists. They can be restored later from the "Show Archived" filter.')->modalSubmitActionLabel('Archive'),
                ]),
            ]);
    }
}
