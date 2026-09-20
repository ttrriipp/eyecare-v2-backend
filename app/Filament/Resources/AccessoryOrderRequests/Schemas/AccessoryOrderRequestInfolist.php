<?php

namespace App\Filament\Resources\AccessoryOrderRequests\Schemas;

use App\Enums\AccessoryOrderRequestStatus;
use App\Models\AccessoryOrderRequest;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AccessoryOrderRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request details')
                    ->schema([
                        TextEntry::make('request_number')
                            ->label('Request #')
                            ->copyable()
                            ->weight('bold'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (AccessoryOrderRequestStatus $state): string => Str::headline($state->value))
                            ->color(fn (AccessoryOrderRequestStatus $state): string => match ($state) {
                                AccessoryOrderRequestStatus::Pending => 'warning',
                                AccessoryOrderRequestStatus::Accepted => 'success',
                                AccessoryOrderRequestStatus::Rejected => 'danger',
                                AccessoryOrderRequestStatus::Cancelled => 'gray',
                            }),
                        TextEntry::make('patient.full_name')
                            ->label('Patient')
                            ->placeholder('Patient record unavailable')
                            ->weight('bold'),
                        TextEntry::make('patient.patient_number')
                            ->label('Patient #')
                            ->placeholder('—'),
                        TextEntry::make('requested_discount_type')
                            ->label('Requested discount')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                        TextEntry::make('subtotal_amount')
                            ->label('Subtotal')
                            ->money('PHP'),
                        TextEntry::make('created_at')
                            ->label('Submitted')
                            ->dateTime('M j, Y g:i A'),
                        TextEntry::make('resolvedBy.full_name')
                            ->label('Resolved by')
                            ->placeholder('Awaiting review'),
                        TextEntry::make('resolved_at')
                            ->label('Resolved at')
                            ->dateTime('M j, Y g:i A')
                            ->placeholder('—'),
                        TextEntry::make('rejection_reason')
                            ->label('Rejection reason')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Order items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->state(fn (AccessoryOrderRequest $record) => $record->items->values())
                            ->table([
                                TableColumn::make('Description'),
                                TableColumn::make('Quantity'),
                                TableColumn::make('Unit Price'),
                                TableColumn::make('Amount'),
                            ])
                            ->schema([
                                TextEntry::make('description')
                                    ->hiddenLabel()
                                    ->wrap(),
                                TextEntry::make('quantity')
                                    ->hiddenLabel(),
                                TextEntry::make('unit_price')
                                    ->hiddenLabel()
                                    ->money('PHP'),
                                TextEntry::make('amount')
                                    ->hiddenLabel()
                                    ->money('PHP'),
                            ])
                            ->placeholder('No items recorded.')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
