<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->label('Order #')
                    ->disabled()
                    ->dehydrated(),
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->disabled()
                    ->dehydrated(),
                Select::make('order_status_id')
                    ->relationship('status', 'name')
                    ->required()
                    ->live(),
                Select::make('prescription_id')
                    ->relationship('prescription', 'id')
                    ->label('Prescription')
                    ->disabled()
                    ->dehydrated(),
                TextInput::make('total_amount')
                    ->label('Total')
                    ->disabled()
                    ->dehydrated()
                    ->prefix('₱'),
                Textarea::make('notes')
                    ->label('Staff notes')
                    ->columnSpanFull(),
            ]);
    }
}
