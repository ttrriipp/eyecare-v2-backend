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
use Filament\Actions\ForceDeleteAction;
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
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'frame' => 'Frame',
                        'lens' => 'Lens',
                        'contact_lens' => 'Contact Lens',
                        'accessory' => 'Accessory',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'frame' => 'info',
                        'lens' => 'success',
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
                    DeleteAction::make()->color('danger'),
                    RestoreAction::make(),
                    ForceDeleteAction::make(),
                ]),
            ])
            ->filters([
                SelectFilter::make('product_type')
                    ->label('Type')
                    ->options([
                        'frame' => 'Frame',
                        'lens' => 'Lens',
                        'contact_lens' => 'Contact Lens',
                        'accessory' => 'Accessory',
                    ]),
                SelectFilter::make('is_active')
                    ->label('Visibility')
                    ->options([
                        '1' => 'Visible',
                        '0' => 'Hidden',
                    ]),
                TrashedFilter::make(),
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
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
