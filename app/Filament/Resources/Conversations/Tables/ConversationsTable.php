<?php

namespace App\Filament\Resources\Conversations\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->default('—')
                    ->searchable(),
                TextColumn::make('appointment.id')
                    ->label('Appointment')
                    ->default('—')
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—'),
                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->default('—'),
                TextColumn::make('messages_count')
                    ->label('Messages')
                    ->counts('messages'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('has_appointment')
                    ->label('Linked to Appointment')
                    ->query(fn (Builder $query) => $query->whereNotNull('appointment_id')),
                Filter::make('has_order')
                    ->label('Linked to Order')
                    ->query(fn (Builder $query) => $query->whereNotNull('order_id')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
