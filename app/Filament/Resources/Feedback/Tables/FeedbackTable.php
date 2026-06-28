<?php

namespace App\Filament\Resources\Feedback\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeedbackTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rating')
                    ->label('Rating')
                    ->sortable(),
                TextColumn::make('appointment.id')
                    ->label('Appointment')
                    ->default('—')
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('comment')
                    ->label('Comment')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->filters([
                SelectFilter::make('rating')
                    ->options([
                        '5' => '⭐⭐⭐⭐⭐ (5)',
                        '4' => '⭐⭐⭐⭐ (4)',
                        '3' => '⭐⭐⭐ (3)',
                        '2' => '⭐⭐ (2)',
                        '1' => '⭐ (1)',
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
