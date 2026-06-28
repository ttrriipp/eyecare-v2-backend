<?php

namespace App\Filament\Resources\Feedback\Tables;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                Filter::make('submitted_from')
                    ->label('Submitted from')
                    ->form([DatePicker::make('date')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date)
                    )),
                Filter::make('submitted_until')
                    ->label('Submitted until')
                    ->form([DatePicker::make('date')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date)
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
