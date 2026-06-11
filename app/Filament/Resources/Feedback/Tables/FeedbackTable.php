<?php

namespace App\Filament\Resources\Feedback\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—'),
                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->default('—'),
                IconColumn::make('staff_reply')
                    ->label('Replied')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->getStateUsing(fn ($record) => ! is_null($record->staff_reply)),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('unreplied')
                    ->label('Awaiting Reply')
                    ->query(fn (Builder $query) => $query->whereNull('staff_reply')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
