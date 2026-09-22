<?php

namespace App\Filament\Resources\AccessoryOrderRequests\Tables;

use App\Enums\AccessoryOrderRequestStatus;
use App\Models\AccessoryOrderRequest;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class AccessoryOrderRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (AccessoryOrderRequest $record): string => $record->items->count().' items'),
                TextColumn::make('subtotal_amount')
                    ->label('Subtotal')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('requested_discount_type')
                    ->label('Discount')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->color(fn (string $state): string => match ($state) {
                        'none' => 'gray',
                        'senior_citizen' => 'warning',
                        'pwd' => 'info',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AccessoryOrderRequestStatus $state): string => Str::headline($state->value))
                    ->color(fn (AccessoryOrderRequestStatus $state): string => match ($state) {
                        AccessoryOrderRequestStatus::Pending => 'warning',
                        AccessoryOrderRequestStatus::Accepted => 'success',
                        AccessoryOrderRequestStatus::Rejected => 'danger',
                        AccessoryOrderRequestStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(AccessoryOrderRequestStatus::class),
            ]);
    }
}
