<?php

namespace App\Filament\Resources\Patients\Tables;

use App\Models\Appointment;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('email')
                    ->searchable()
                    ->placeholder('Walk-in'),
                TextColumn::make('last_visit')
                    ->label('Last Visit')
                    ->state(fn (User $record): string => Appointment::query()
                        ->where('customer_id', $record->id)
                        ->latest('scheduled_at')
                        ->value('scheduled_at')
                        ? Carbon::parse(
                            Appointment::query()
                                ->where('customer_id', $record->id)
                                ->latest('scheduled_at')
                                ->value('scheduled_at')
                        )->format('M j, Y')
                        : '—'
                    ),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->state(fn (User $record): int => Order::query()
                        ->where('customer_id', $record->id)
                        ->count()
                    ),
            ])
            ->recordActions([
                EditAction::make()->label('View'),
            ])
            ->defaultSort('name');
    }
}
