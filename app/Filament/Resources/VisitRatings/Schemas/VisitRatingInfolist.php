<?php

namespace App\Filament\Resources\VisitRatings\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VisitRatingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('patient.full_name')
                            ->label('Patient'),

                        TextEntry::make('appointment.appointment_number')
                            ->label('Appointment'),

                        TextEntry::make('appointment.scheduled_at')
                            ->label('Visit Date')
                            ->dateTime('M j, Y'),

                        TextEntry::make('optometrist.full_name')
                            ->label('Optometrist')
                            ->placeholder('—'),

                        TextEntry::make('rating')
                            ->label('Rating')
                            ->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state)),

                        TextEntry::make('comment')
                            ->label('Comment')
                            ->placeholder('No comment')
                            ->columnSpanFull(),

                        TextEntry::make('created_at')
                            ->label('Submitted')
                            ->dateTime('M j, Y g:i A'),
                    ])
                    ->columns(2),
            ]);
    }
}
