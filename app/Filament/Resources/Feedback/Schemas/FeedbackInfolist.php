<?php

namespace App\Filament\Resources\Feedback\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FeedbackInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('customer.name')
                    ->label('Customer'),
                TextEntry::make('rating')
                    ->label('Rating'),
                TextEntry::make('appointment.id')
                    ->label('Appointment')
                    ->default('—')
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—'),
                TextEntry::make('order.order_number')
                    ->label('Order')
                    ->default('—'),
                TextEntry::make('comment')
                    ->label('Comment')
                    ->default('—')
                    ->columnSpanFull(),
                TextEntry::make('staff_reply')
                    ->label('Staff Reply')
                    ->default('No reply yet')
                    ->columnSpanFull(),
                TextEntry::make('repliedBy.name')
                    ->label('Replied By')
                    ->default('—'),
                TextEntry::make('replied_at')
                    ->label('Replied At')
                    ->dateTime()
                    ->placeholder('—'),
            ]);
    }
}
