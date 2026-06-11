<?php

namespace App\Filament\Resources\Conversations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ConversationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('customer.name')
                    ->label('Customer'),
                TextEntry::make('subject')
                    ->label('Subject')
                    ->default('—'),
                TextEntry::make('appointment.id')
                    ->label('Appointment')
                    ->default('—')
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—'),
                TextEntry::make('order.order_number')
                    ->label('Order')
                    ->default('—'),
                TextEntry::make('created_at')
                    ->label('Started')
                    ->dateTime(),
            ]);
    }
}
